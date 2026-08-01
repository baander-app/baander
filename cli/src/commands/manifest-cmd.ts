import { Command } from 'commander';
import { generateManifest } from '../manifest.js';
import { info, success } from '../lib/output.js';

export function createManifestCommand(): Command {
  return new Command('manifest')
    .description('Force-regenerate the Symfony command manifest')
    .action(async () => {
      info('Generating manifest from PHP container...');
      await generateManifest();
      success('Manifest generated and cached.');
    });
}
