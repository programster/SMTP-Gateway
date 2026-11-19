<?php

/*
 * The retention settings (settings.yaml's email_record_days, email_content_days and email_attachment_days, set on the
 * Settings page), each of which has to fit within the one before it.
 */

declare(strict_types = 1);

require_once __DIR__ . '/TestSuite.php';

$suite = new TestSuite('Retention policy');

/**
 * Describe the policy some settings make, or why they're refused.
 */
$describe = function (string $records, string $content, string $attachments) : string {
    try
    {
        $policy = EmailRetentionPolicy::createFromSettings($records, $content, $attachments);
        $onFinish = $policy->removesAttachmentsOnFinish() ? 1 : 0;
        $result = "content={$policy->getContentDays()} attachments={$policy->getAttachmentDays()} onFinish={$onFinish}";
    }
    catch (InvalidInputException $e)
    {
        $result = 'refused: ' . $e->getMessage();
    }

    return $result;
};

$expectations = [
    'the defaults' => [['90', '-1', '-1'], 'content=-1 attachments=-1 onFinish=0'],
    'content for 30 days, attachments for 7' => [['90', '30', '7'], 'content=30 attachments=7 onFinish=0'],
    'content for 30 days, no attachments' => [['90', '30', '-1'], 'content=30 attachments=-1 onFinish=1'],
    'content with the record, and attachments' => [['90', '0', '0'], 'content=90 attachments=90 onFinish=0'],
    'content with the record, attachments for 7 days' => [['90', '0', '7'], 'content=90 attachments=7 onFinish=0'],
    'attachments as long as the content' => [['90', '30', '30'], 'content=30 attachments=30 onFinish=0'],
    'attachments with content that isn\'t kept' => [['90', '-1', '0'], 'content=-1 attachments=-1 onFinish=0'],
];

foreach ($expectations as $name => [$settings, $expected])
{
    $described = $describe(...$settings);
    $suite->check($name, $described === $expected, $described);
}

$refusals = [
    'content kept longer than the record refused' => [['90', '91', '-1'], 'Email content can\'t be kept for longer'],
    'attachments kept longer than the content refused' => [['90', '30', '31'], 'Attachments can\'t be kept for longer'],
    'attachments kept longer than the record refused' => [['90', '0', '91'], 'Attachments can\'t be kept for longer'],
    'attachments kept with no content refused' => [['90', '-1', '7'], 'Attachments can\'t be kept (7 days) when'],
    'records kept for 0 days refused' => [['0', '-1', '-1'], 'How long email records are kept'],
    'records setting that isn\'t a number refused' => [['abc', '-1', '-1'], 'How long email records are kept'],
    'content setting that isn\'t whole refused' => [['90', '1.5', '-1'], 'How long email content is kept is invalid'],
    'attachment setting below -1 refused' => [['90', '30', '-2'], 'How long attachments are kept is invalid'],
];

foreach ($refusals as $name => [$settings, $expectedStart])
{
    $described = $describe(...$settings);
    $suite->check($name, str_starts_with($described, 'refused: ' . $expectedStart), $described);
}

$policy = EmailRetentionPolicy::createFromSettings('90', '30', '7');
$described = $policy->describeContent() . ' / ' . $policy->describeAttachments();
$expected = 'kept for 30 days after it has been sent / removed 7 days after it has been sent';
$suite->check('describes itself', $described === $expected, $described);
$sql = EmailRetentionPolicy::createFromSettings('90', '-1', '-1')->getAttachmentsDueForRemovalSql();
$suite->check('nothing for the attachment remover when content isn\'t kept', $sql === '', $sql);
$sql = EmailRetentionPolicy::createFromSettings('90', '30', '0')->getAttachmentsDueForRemovalSql();
$suite->check('nothing for it when attachments are kept with the content', $sql === '', $sql);

$suite->finish();
