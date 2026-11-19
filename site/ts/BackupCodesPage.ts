// The page shown once after generating backup codes, with buttons to print, download or copy them.
import { BackupCodes } from './BackupCodes.js';
import { Elements } from './Elements.js';

new BackupCodes(Elements.findById('backup-codes', HTMLElement));
