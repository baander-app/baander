const path = require('node:path');
const { flipFuses, FuseVersion, FuseV1Options } = require('@electron/fuses');
const fs = require('fs');

module.exports = async function afterPack(context) {
  const { appOutDir, packager } = context;
  const platform = packager.platform.nodeName; // 'darwin' | 'linux' | 'win32'
  const productName = packager.appInfo.productFilename;

  if (!productName) {
    throw new Error('Unable to resolve product filename from electron-builder packager');
  }

  let executable;
  if (platform === 'darwin') {
    executable = path.join(appOutDir, `${productName}.app`, 'Contents', 'MacOS', productName);
  } else if (platform === 'win32') {
    executable = path.join(appOutDir, `${productName}.exe`);
  } else {
    // linux (unpacked)
    executable = path.join(appOutDir, productName);
  }

  /** @type {import('@electron/fuses').MutableFuseWire<FuseV1Options>} */
  const fuses = {
    version: FuseVersion.V1,
    onlyLoadAppFromAsar: true,
    runAsNode: false,
    enableNodeOptionsEnvironmentVariable: false,
    enableNodeCliInspectArguments: false,
    grantFileProtocolExtraPrivileges: false,
    enableCookieEncryption: true,
  };

  console.log(`[fuses] Flipping fuses for ${platform} at ${executable}`);
  await flipFuses(executable, fuses);
  console.log('[fuses] Done');

  // prune locales
  const fs = require('fs');
  const localeDir = context.appOutDir + '/locales/';

  fs.readdir(localeDir, function (err, files) {
    if (!(files && files.length)) return;
    for (let i = 0, len = files.length; i < len; i++) {
      if (!(files[i].startsWith('en') || files[i].startsWith('da'))) {
        fs.unlinkSync(localeDir + files[i]);
      }
    }
  });
};
