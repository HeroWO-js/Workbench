#!/bin/env php
<?php
chdir(__DIR__);
error_reporting(-1);
setlocale(LC_ALL, 'en_US.UTF-8');
date_default_timezone_set('UTC');

set_error_handler(function ($severity, $msg, $file, $line) {
  throw new ErrorException($msg, 0, $severity, $file, $line);
}, -1);

define('WINDOWS', !strncasecmp(PHP_OS, 'win', 3));

if (WINDOWS) {
  // Needed for minify() (minify, Node), do_wav2ogg() (oggenc2).
  putenv('PATH='.getenv('PATH').';'.__DIR__.'\\tools');
}

if (count($argv) > 1) {
  $targets = [
    'databank'  => 'd',
    'tutorial'  => 't',
    'maps'      => 'm',
  ];

  foreach (get_defined_functions()['user'] as $func) {
    strncmp($func, 'do_', 3) or $targets += [substr($func, 3) => false];
  }

  $arg = $argv[1];

  if ($arg === '-h') {
    // Help, help...
  } elseif (count($argv) === 2 and
            (isset($targets[$target = strtolower($arg)]) or
             $target = array_search($target, $targets, true))) {
    $SKIP_PENDING = true;
    exit((int) !call_user_func("do_$target"));
  } elseif (count($argv) <= 3 and in_array($arg, ['min', 'minify'])) {
    exit((int) !minify($argv[2] ?? null));
  } elseif (file_exists($arg)) {
    exit((int) !h3m2herowo($arg, array_slice($argv, 2)));
  } else {
    printf('Unrecognized arguments: %s%s',
      join(', ', array_slice($argv, 1)), PHP_EOL);
    printf(PHP_EOL);
  }

  $ds = DIRECTORY_SEPARATOR;
  echo <<<ECHO
Interactively update everything:
  update.php
Show this help text:
  update.php -h
Interactively update specific target, ignoring prerequisites:
  update.php TARGET
Convert specific HoMM 3 map to maps$ds:
  update.php MAP.H3M|DIR/ [-options of h3m2herowo.php | -h]
Minify CSS and JS resources for game client:
  update.php min[ify] [/]

Useful h3m2herowo.php -options (give -h for all):
  -s CHARSET      iconv charset for H3M strings; give -h to see allowed values
  -ei             ignore maps that couldn't convert, try to convert everything
  -ew             fail on any warning
  -nx             do nothing for already converted maps
  -w [MS]         watch mode: convert again when input map has changed

Valid TARGET and their short aliases:

ECHO;

  foreach ($targets as $target => $short) {
    printf('  %1s  %s%s', $short, $target, PHP_EOL);
  }

  exit(2);
}

// This script doesn't check writability of directories and files because it
// depends on local set-up and because update.php is mostly meant for local
// development.
//
// In production, you'd want these to be writable:
// * log/           - nginx' access_log/error_log, if not storing in /var/log
// * maps/          - if allowing user map uploads via maps.php
// * tmp/           - PHP's sys_temp_dir, suggested on a zram mount
//
// Additionally, if hosting a HeroWO WebSocket server:
// * core/server/websocket-repl.log
// * replays/       -replays
// * reports/       --report-directory
if (checkPhpExtensions() and
    printf(PHP_EOL) and
    checkSubmodules() and
    printf(PHP_EOL) and
    createFolders() and
    printf(PHP_EOL) and
    checkDiskSpace() and
    printf(PHP_EOL) and
    writeApiConfig() and
    printf(PHP_EOL) and
    convertH3Data() and
    printf(PHP_EOL) and
    removeTemporary() and
    $finished = true) {
  echo <<<ECHO
 ___________________________________________________________
|                                                           |
|         HeroWO Workbench Initialized Successfully         |
| Open core/index.php in your web browser to start the game |
|___________________________________________________________|

ECHO;
}

if (WINDOWS and !config('ignorePause')) {
  printf(PHP_EOL);
  printf('Press Enter to close this window');
  readSTDIN();
}

exit(empty($finished) ? 3 : 0);

function readSTDIN() {
  $s = fgets(STDIN);
  $s === false and exit(-1);    // Ctrl+C
  return $s;
}

function folders($flag = null) {
  $folders = [
    // databank.php outputs here.
    'databanks'         => ['create' => true],
    // maps.php outputs here. api.php reads from here.
    'maps'              => ['create' => true],
    // User places H3bitmap.lod content here.
    'BMP'               => ['create' => true, 'remove' => true],
    // User places H3sprite.lod content here.
    'DEF'               => ['create' => true, 'remove' => true],
    // User places Heroes3.snd content here.
    'WAV'               => ['create' => true, 'remove' => true],
    'WAV-PCM'           => ['remove' => true],
    // User places content of game's MP3\ folder here
    'MP3'               => ['create' => true],
    // User places HoMM 3 maps for convertion here.
    'H3M'               => ['create' => true],
    // User places DefPreview output here.
    'DEF-extracted'     => ['create' => true, 'remove' => true],
    'BMP-PNG'           => [],
    'DEF-PNG'           => [],
    'WAV-OGG'           => [],
    // The following are not currently handled by update.php (see do_bik()).
    'BIK-APNG'          => [],
    'BIK-WAV-OGG'       => [],
    'CDROM-BIK-WAV-OGG' => [],
  ];

  if (func_num_args()) {
    if ($flag) {
      $folders = array_filter($folders, function ($folder) use ($flag) {
        return !empty($folder[$flag]);
      });
    }

    return array_keys($folders);
  } else {
    return $folders;
  }
}

function folderSize($path) {
  static $blksizeOverride;

  if ($blksizeOverride === null) {
    $blksizeOverride = config('blksize') ?: false;
  }

  $res = 0;

  if (file_exists($path)) {
    foreach (scandir($path, SCANDIR_SORT_NONE) as $file) {
      if ($file === '.' or $file === '..') {
        continue;
      }
      try {
        $stat = stat($full = "$path/$file");
      } catch (Throwable $e) {
        continue;
      }
      if ($stat['mode'] & 0x4000) {
        $res += folderSize($full);
      } elseif ($stat['mode'] & 0x8000) {
        // blksize is -1 on Windows so using 4K (default NTFS cluster size).
        $blksize = $blksizeOverride === false
          ? $stat['blksize'] >= 512 ? $stat['blksize'] : 4096
          : $blksizeOverride;
        $res += ceil($stat['size'] / $blksize) * $blksize;
      }
    }
  }

  return $res;
}

function config($key = null, $value = null) {
  static $config;

  if ($config === null) {
    try {
      is_file('config.php') and $config = include('config.php');
    } catch (Throwable $e) {}
    is_array($config) or $config = [];
  }

  if ($key === null) {
    return $config;
  } elseif (func_num_args() < 2) {
    return $config[$key] ?? null;
  } else {
    $config[$key] = $value;
    writeFile('config.php', "<?php\nreturn ".var_export($config, true).';');
    return $value;
  }
}

function writeFile($path, $data) {
  try {
    file_put_contents($path, $data, LOCK_EX);
  } catch (Throwable $e) {
    printf(PHP_EOL);
    printf('=> Looks like %s%s is not writable. The error message was:%s',
      getcwd().DIRECTORY_SEPARATOR, $path, PHP_EOL);
    printf('   %s%s', trim($e->getMessage()), PHP_EOL);
    exit(1);
  }
}

function currentDatabank() {
  require_once 'core/api.php';
  return keyValue('databank');
}

function checkPhpExtensions() {
  $extensions = [
    'gd' => 'convert HoMM 3 images',
    'hash' => false,
    'iconv' => 'convert HoMM 3 maps (.h3m)',
    'json' => false,
    'mbstring' => 'generate databanks (databank.php)',
    'pdo' => 'store web configuration (index.php, api.php, maps.php, etc.)',
    'pdo_sqlite' => 'provide default database engine for web configuration',
    'zip' => 'upload and download maps (maps.php)',
    'zlib' => 'convert HoMM 3 maps (.h3m), talk to remote server (api.php)',
  ];

  $missing = [];

  foreach ($extensions as $extension => $optional) {
    checkPhpExtension($extension, $optional) or $missing[] = $extension;
  }

  $config = config('ignoreExtensions') ?: [];
  $ignored = array_intersect($missing, $config);
  $newMissing = array_values(array_diff($missing, $config));

  if ($ignored) {
    printf(PHP_EOL);
    printf('Skipping previously ignored extensions: %s%s',
      join(', ', $ignored), PHP_EOL);
  }

  if ($newMissing) {
    printf(PHP_EOL);
    printf('=> Some PHP extensions are missing:%s', PHP_EOL);

    foreach ($newMissing as $i => $extension) {
      printf('   % 2d. %s%s', $i + 1, $extension, PHP_EOL);
    }

    printf(PHP_EOL);
    printf('You should enable missing extensions in PHP. Press Enter to exit.%s', PHP_EOL);
    if ($ini = stripos(php_ini_loaded_file(), '\\xampp\\php\\php.ini') !== false) {
      printf(PHP_EOL);
      printf('Type "x" to try enabling them in XAMPP automatically.', PHP_EOL);
    }
    printf(PHP_EOL);
    printf('Or type "yes" and press Enter if you wish to proceed anyway (may cause future errors).%s', PHP_EOL);

    $s = trim(readSTDIN());
    if ($ini and !strcasecmp($s, 'x')) {
      $ini = file_get_contents(php_ini_loaded_file());
      array_unshift($newMissing, "\r\n# Enabled by HeroWO/update.php:");
      $ini .= join("\r\nextension=", $newMissing);
      writeFile(php_ini_loaded_file(), $ini);
      run([true, __FILE__]);
    }
    if (stripos($s, 'yes') === false) {
      return;
    }

    config('ignoreExtensions', array_merge($config, $newMissing));
  }

  return true;
}

function checkPhpExtension($extension, $optional = false) {
  printf('PHP extension %s required to %s ... ',
    $extension, $optional ?: 'operate');

  if (extension_loaded($extension)) {
    return printf('found%s', PHP_EOL);
  } else {
    printf('MISSING%s', PHP_EOL);
  }
}

function checkSubmodules() {
  $submodules = [
    'core' => 'index.php',
    'core/client/nodash' => 'nodash.min.js',
    'core/client/PathAnimator' => 'pathAnimator.js',
    'core/client/r.js' => 'require.js',
    'core/client/sqimitive' => 'sqimitive.min.js',
    'core/databank/h3m2json' => 'h3m2json.php',
    'core/Phiws' => 'Phiws/BaseTunnel.php',
    'core/noXXXep' => 'noXXXep.php',
    'core/source-map' => 'source-map.js',
  ];

  $missing = false;

  foreach ($submodules as $path => $file) {
    $missing |= $thisMissing = !is_file("$path/$file");
    printf('Submodule %s ... %s%s', strtr($path, '/', DIRECTORY_SEPARATOR),
      $thisMissing ? 'MISSING' : 'found', PHP_EOL);
  }

  if ($missing) {
    printf(PHP_EOL);
    printf('=> Some parts of this repository are missing. Clone it again with --recurse-submodules enabled and try again.');
  }

  return !$missing;
}

function createFolders() {
  $failed = false;

  foreach (folders('create') as $path) {
    if (!is_dir($path)) {
      $status = 'CREATING';
    } elseif ($failed |= !is_writable($path)) {
      $status = 'NOT WRITABLE';
    } else {
      $status = 'found';
    }
    printf('Folder %s ... %s%s', $path, $status, PHP_EOL);

    try {
      is_dir($path) or mkdir($path, 0755, true);
    } catch (Throwable $e) {
      $failed |= 2;
      printf(PHP_EOL);
      printf('=> Could not create %s%s. The error message was:%s',
        getcwd().DIRECTORY_SEPARATOR, $path.DIRECTORY_SEPARATOR, PHP_EOL);
      printf('   %s%s', trim($e->getMessage()), PHP_EOL);
      continue;
    }
  }

  if ($failed > 1) {
    printf(PHP_EOL);
    printf('=> Some folders are not writable.%s', PHP_EOL);
  }

  return !$failed;
}

function checkDiskSpace() {
  printf('Checking available disk space ... ');

  if (config('ignoreDiskSpace')) {
    printf('DISABLED%s', PHP_EOL);
  } elseif (config('diskSpaceCheck') > time()) {
    printf('DID RECENTLY%s', PHP_EOL);
  } else {
    // DEF-extracted takes up ~6.5 GiB. Files needed for game client ~1 GiB.
    // Also converted official H3M maps 11+ GiB.
    $min = 20 * 2 ** 30;
    $diskFree = disk_free_space('.');
    $used = $diskFree < $min
      ? array_sum(array_map('folderSize', folders(null))) : null;
    $free = $diskFree + $used;

    printf('%s MiB free %s(%s)%s', number_format($diskFree / 2 ** 20),
      isset($used) ? sprintf('+ %s MiB in Workbench ', number_format($used / 2 ** 20)) : '',
      $free < $min ? 'NOT ENOUGH' : 'good', PHP_EOL);

    if ($free < $min) {
      printf(PHP_EOL);
      printf('=> Not enough disk space for initial convertion (need %.1f GiB). Press Enter to exit, then free up at least %.1f GiB and try again.%s',
        $min / 2 ** 30, ($min - $used) / 2 ** 30, PHP_EOL);
      printf(PHP_EOL);
      printf('Or type "yes" and press Enter if you wish to proceed anyway (may cause future errors).%s', PHP_EOL);
      printf(PHP_EOL);
      printf('Note: most of that (11+ GiB) is taken by converted maps. Enabling file system compression on the below folder will save you 90% of needed disk space. Continue if you\'ve done that.%s', PHP_EOL);
      printf(PHP_EOL);
      printf('%s%s', __DIR__.DIRECTORY_SEPARATOR.'maps', PHP_EOL);
      if (WINDOWS) {
        printf(PHP_EOL);
        printf('Open Windows Explorer, Properties of the above folder, click Advanced (General tab), tick "Compress contents to save disk space", click OK, OK again, select "Apply changes to the folder, subfolders and files" and finally OK.%s', PHP_EOL);
      }

      $s = readSTDIN();
      if (stripos($s, 'yes') === false) {
        return;
      }

      config('ignoreDiskSpace', true);
    }

    // Calculating size of a large directory takes a few seconds so do it once
    // in a while.
    isset($used) and config('diskSpaceCheck', time() + 180);
  }

  return true;
}

function writeApiConfig() {
  $found = is_file($file = 'core/api-db.php');
  printf('API config file (%s) ... %s%s', $file, $found ? 'found' : 'CREATING', PHP_EOL);

  if (!$found) {
    writeFile($file, "<?php return new PDO('sqlite:'.__DIR__.'/../api.sqlite');");
  }

  printf(PHP_EOL);
  require_once 'core/api.php';

  printf('API config values ... %s%s', pdo()->getAttribute(PDO::ATTR_DRIVER_NAME), PHP_EOL);

  $defaults = [
    'maps'          => ['maps', '../maps'],
    'mapsURL'       => ['maps/', '../maps/'],
    'databanks'     => ['databanks', '../databanks'],
    'databanksURL'  => ['databanks/', '../databanks/'],
  ];

  $update = pdo()->prepare('UPDATE keyValues SET value = ? WHERE `key` = ? AND value = ?');

  foreach ($defaults as $key => [$default, $value]) {
    $old = keyValue($key);

    $update->bindValue(1, $value);
    $update->bindValue(2, $key);
    $update->bindValue(3, $default);
    $update->execute();

    $current = keyValue($key);

    if ($old !== $current) {
      printf('  %s = %s (changed from the default %s)%s',
        $key, $current, $default, PHP_EOL);
    } elseif ($old === $value) {
      printf('  %s = %s (good)%s', $key, $old, PHP_EOL);
    } else {
      printf('  %s = %s (should be %s, DIFFERENT)%s', $key, $old, $value, PHP_EOL);
    }
  }

  return true;
}

function removeTemporary() {
  $found = false;

  foreach (folders('remove') as $path) {
    $size = folderSize($path);
    $found |= $thisFound = is_dir($path);
    printf('Temporary folder %s ... %s%s', $path,
      $thisFound ? sprintf('FOUND (%s)',
      $size ? number_format($size / 2 ** 20).' MiB' : 'empty') : 'not found',
      PHP_EOL);
  }

  if ($found and !config('ignoreTemporary')) {
    printf(PHP_EOL);
    printf('=> Initial convertion is finished. You can delete the folders listed above to reclaim disk space. However, if you plan to change game data later then you should keep them to avoid going all over the process again.%s', PHP_EOL);
    printf(PHP_EOL);
    printf('   Press Enter to continue');

    readSTDIN();
    config('ignoreTemporary', true);
  }

  return true;
}

function minify($urlPrefix = null) {
  $minify = stripos(exec('minify --version', $output, $code), 'minify v') !== false && !$code;
  $node = preg_match('/^v\\d+\\./', exec('node -v', $output, $code)) && !$code;
  //$uglify = stripos(exec('uglifyjs -v', $output, $code), 'uglify-js') !== false && !$code;

  printf('minify ... %s%s', $minify ? 'found' : 'MISSING', PHP_EOL);
  printf('node ... %s%s', $node ? 'found' : 'MISSING', PHP_EOL);
  //printf('uglifyjs ... %s%s', $uglify ? 'found' : 'MISSING', PHP_EOL);

  if (!$minify) {
    printf(PHP_EOL);
    printf('=> minify is required: %s%s', 'https://github.com/tdewolff/minify/releases', PHP_EOL);
    printf(PHP_EOL);
    printf(WINDOWS ? "   Download the latest release and put minify.exe into Workbench' tools.%s" : '   Install minify using your package manager.%s', PHP_EOL);
  }

  if (!$node) {
    printf(PHP_EOL);
    printf('=> Node.js is required.');
    if (WINDOWS) {
      printf(" Either install it from https://nodejs.org or download this to Workbench' tools:%s", PHP_EOL);
      printf('   %s%s', 'https://nodejs.org/dist/latest/win-x86/node.exe', PHP_EOL);
    } else {
      printf(' Install nodejs using your package manager.%s', PHP_EOL);
    }
  }

  //if (!$uglify) {
  //  printf(PHP_EOL);
  //  printf('=> uglifyjs is required. Install uglify-js using npm or install uglifyjs using your package manager.%s', PHP_EOL);
  //}

  if (!$minify or !$node /*or !$uglify*/) {
    return;
  }

  printf(PHP_EOL);

  $ok = true;
  $databank = 'databanks/'.currentDatabank();

  // Used by core/exception.php.
  run(['git', '-C', 'core', 'rev-parse', '--verify', 'HEAD'],
      ['>'.escapeshellarg("$databank/revision.txt")]);

  $ok &= run(['node', 'core/client/r.js/dist/r.js', '-o', 'build.js',
              "out=$databank/herowo.min.js"]);

  $minify = ['minify', '--css-precision', 3, '-o'];

  // There was a change in behaviour in some recent version: previously, -o
  // produced a concatenated file, now it creates a folder given multiple files.
  exec('minify 2>&1 -b', $output, $code);
  $code === 1 and array_splice($minify, 3, 0, '-b');

  $cmd = $minify;
  $cmd[] = $output = "$databank/herowo.min.css";
  $cmd[] = "$databank/menu.css";
  $cmd[] = 'core/herowo.css';

  // This must match minified CSS too.
  $re = '~@import "([/\\w-]+\\.css)";~u';
  preg_match_all($re, file_get_contents('core/herowo.css'), $import);

  foreach ($import[1] as $file) {
    $cmd[] = "core/$file";
  }

  $ok &= run($cmd);

  // XXX=R At this moment core styles assume the parent directory is the
  // Workbench and use references like url(../BMP-PNG/...). Replacing them.
  //
  // XXX=R Also replacing references to core/custom-graphics as if
  // custom-graphics was part of the Workbench (i.e. the server with statics).
  // For now, on a development system a symlink or web server rewrite rule has
  // to be set up for this folder (unless you just copy it of course).
  $folders = join('|', array_map(function ($s) {
    return preg_quote($s, '~');
  }, folders(null)));

  $re2 = '~
    (
      \\b url \\(
      [\'"]?
    )
    (?:
      \\.\\./ ('.$folders.')
    | (custom-graphics)
    )
    /
  ~xu';

  writeFile($output, preg_replace([$re, $re2], ['', '$1../../$2$3/'],
    file_get_contents($output)));

  foreach (glob('databanks/*/combined.css', GLOB_NOSORT | GLOB_ERR) as $file) {
    $cmd = $minify;
    $cmd[] = $file;
    $cmd[] = $file;
    $ok &= run($cmd);
  }

  if (isset($urlPrefix)) {
    // combined.json includes audio.json that uses -au.
    $mask = 'databanks/*/{combined.css,herowo.min.css,combined.json}';
    foreach (glob($mask, GLOB_NOSORT | GLOB_BRACE | GLOB_ERR) as $file) {
      writeFile($file, str_replace('../../', $urlPrefix, file_get_contents($file)));
    }
  }

  // Could run minify on combined.json but it's already minimal.

  if ($ok) {
    printf(PHP_EOL);
    printf('Finished. %s%s', isset($urlPrefix) ? "URLs were rewritten to $urlPrefix." : 'Give ?d to index.php to start client in production mode.', PHP_EOL);
    printf('Use optimize-png.sh to make images smaller in production.%s', PHP_EOL);
  }

  return $ok;
}

// You can perform the following by hand to achieve the effect of this script
// (stars mark optional steps whose results are already part of the Core or
// Workbench repository):
//
//   1. Run MMArchive, extract H3bitmap.lod to BMP
//   2. Run MMArchive, extract H3sprite.lod to DEF
//   3. Run MMArchive, extract Heroes3.snd to WAV
//   4. Copy content of MP3  folder from the game's folder to MP3 in Workbench
//   5. Copy content of Maps folder from the game's folder to H3M in Workbench
// * 6. Generate custom DEFs:
//      a. cd custom-graphics\DEF ; php gen.php (correct paths in the template)
//      b. Run H3DefTool, open each HDL, press Make Def
//      c. Copy produced DEFs to DEF
//   7. Run DefPreview, call these commands on each DEF in DEF\ in order
//      (output to DEF-extracted; can automate using the provided AHK script):
//      a. Extract All for DefTool
//      b. Extract Picture(s)
//      c. Export Defmaker DefList
// * 8. Use Photoshop or another tool to convert each frame (bitmap) in
//      CRADVNTR.DEF, CRCOMBAT.DEF and CRDEFLT.DEF to .cur; output to
//      core\custom-graphics\DEF-CUR (\CRADVNTR\CursrA00.cur, etc.)
// * 9. cur-hotspot.php DEF-CUR\CRADVNTR
//      cur-hotspot.php DEF-CUR\CRCOMBAT
//      cur-hotspot.php DEF-CUR\CRDEFLT
// *10. CMNUMWIN2png.php BMP\CMNUMWIN.BMP core\custom-graphics\CMNUMWIN
//  11. bmp2png.php BMP BMP-PNG
// *12. Run potrace to generate .geojson from BMPs as described in bmp2shape.php
// *13. bmp2shape.php -b BMP -g BMP-geojson -o core\databank\shapes.json
//  14. def2png.php DEF-extracted DEF-PNG -p BMP\PLAYERS.PAL -b BMP-PNG
//  15. Run adpcm2pcm on each WAV (output to WAV-PCM)
//  16. Run oggenc or oggenc2 on each WAV-PCM (output to WAV-OGG)
//  17. Generate databank:
//      php -d memory_limit=1G
//        databank.php -p -t BMP -g core/databank/shapes.json
//        -d DEF-PNG -du ../../DEF-PNG/
//        -a MP3 -a WAV-OGG -au ../../
//        -b BMP-PNG/bitmap.css -bu ../../BMP-PNG/
//        -v sod databanks
//  18. Generate maps:
//      php h3m2herowo.php -d databanks/sod -M -ih H3M/Tutorial.tut maps
//      php -d memory_limit=3G h3m2herowo.php -d databanks/sod -M -ew H3M maps
//
// The above doesn't include APNG/OGG generation from BIK/SMK in Heroes3.vid or
// CD-ROM. Refer to Formats.txt on how to do that, and see do_bik().
//
// XXX Replace MMArchive with the console LodTool, feeding it a script like:
// load|...\H3bitmap.lod
// +|*
// export|...\BMP
function convertH3Data() {
  // Stuff required by game client.
  return !pending('bmp2png') and !pending('def2png') and !pending('mp3') and
         !pending('wav2ogg') and !pending('databank') and !pending('maps') and
         !pending('bik');
}

function checkFiles($base, array $expected) {
  $missing = array_filter($expected, function ($file) use ($base) {
    return !is_file($base.$file);
  });

  $missing and printf('%s(...) ', trim(substr(join(' ', $missing), 0, 30), '(). '));
  return !$missing;
}

function checkFileCopies($src, $srcExt, $dest, $destExt) {
  if (is_dir($src)) {
    $len = strlen($srcExt);

    $missing = array_filter(
      scandir($src, SCANDIR_SORT_NONE),
      function ($file) use ($len, $src, $srcExt, $dest, $destExt) {
        return !strcasecmp(substr($file, -$len), $srcExt) and
               !is_file($dest.substr($file, 0, -$len).$destExt);
      }
    );

    $missing and printf('%s(...) ', trim(substr(join(' ', $missing), 0, 30), '(). '));
    return !$missing;
  }
}

function run(array $args, array $rawArgs = []) {
  if ($silent = $args[0] === false) {
    array_shift($args);
  }

  if ($php = $args[0] === true) {
    $args[0] = PHP_BINARY;
  }

  $cmd = [];

  foreach ($args as $arg) {
    // Since we show constructed command line to the user, make it more pretty
    // by removing redundant quotes around known-safe arguments.
    $cmd[] = ltrim($arg, 'a..zA..Z.-') === '' ? $arg : escapeshellarg($arg);
  }

  $line = join(' ', array_merge($cmd, $rawArgs));
  $sep = str_repeat('-', min(78, strlen($line))).PHP_EOL;
  $silent or printf('Executing %s%s', $line, PHP_EOL);
  $silent or printf($sep);
  system($line, $code);
  $silent or printf($sep);

  if ($code) {
    $silent and printf('Executed %s%s', $line, PHP_EOL);
    printf(PHP_EOL);
    // Skip php -d ...
    for (; !strncmp(ltrim($cmd[$php] ?? '', '\'"'), '-', 1); $php += 1) ;
    printf('=> %s exited with code %d. Check the above output and try again.%s',
      $cmd[$php] ?? 'Command', $code, PHP_EOL);
  }

  return !$code;
}

function convertFolder($src, $srcExt, $dest, $destExt, array $cmd) {
  foreach ($cmd as $i => $value) {
    is_float($value) and is_nan($value) /*NAN*/ and $in = $i;
    $value === INF and $out = $i;
  }

  is_dir($src) or mkdir($src);
  $len = strlen($srcExt);
  $first = true;

  foreach (scandir($src, SCANDIR_SORT_NONE) as $file) {
    if (!strcasecmp(substr($file, -$len), $srcExt)) {
      $run = $cmd;
      $run[$in] = "$src/$file";
      $run[$out] = "$dest/".substr($file, 0, -$len).$destExt;
      $first or array_unshift($run, false);
      if (!run($run)) { return; }
      $first = false;
    }
  }

  //$input = null;
  //$length = 0;
  //
  //foreach (scandir($dest, SCANDIR_SORT_NONE) as $file) {
  //  if (!strcasecmp(substr($file, -$len), $srcExt)) {
  //    $input or $input = $cmd;
  //    $length += 3 /*quotes/space*/ + strlen($input[] = "$dest/$file");
  //
  //    // Windows' cmd.exe seems to impose a command line limit of about 8K.
  //    if ($length > 5000) {
  //      if (!run($input)) { return; }
  //      $input = null;
  //      $length = 0;
  //    }
  //  }
  //}
  //
  //if (!run($input)) { return; }

  return true;
}

function h3m2herowo($input, array $arguments = []) {
  $cmd = array_merge(
    [
      true,
      '-d memory_limit=3G',
      'core/databank/h3m2herowo.php',
      '-M',
      $input,
      'maps',
      '-d', 'databanks/'.currentDatabank(),
    ],
    $arguments,
    // -ew -s RU ...
    config('h3m2herowo') ?? []
  );

  return run($cmd);
}

function pending($step) {
  static $level = 0;
  global $SKIP_PENDING;

  if (empty($SKIP_PENDING)) {
    printf('%sChecking for [%s] ... ', str_repeat('  ', $level++), $step);
    try {
      $done = call_user_func("done_$step");
      printf('%s%s', $done ? 'found' : 'MISSING', PHP_EOL);
      if (!$done) {
        // 0 = task succeeded, 1 = failed.
        return (int) !call_user_func("do_$step");
      }
    } finally {
      $level--;
    }
  }
}

function done_bmp() {
  // Several random files of each extension in H3bitmap.lod, in upper-case.
  // PCX files are converted to BMP when extracted hence PCX is not included.
  return checkFiles('BMP/', [
    'BOTGA2H.BMP',
    'HPS071DK.BMP',
    'PUZFOR12.BMP',
    'CRGEN1.TXT',
    'BIGFONT.FNT',
    'SECRET.H3C',
    'PLAYERS.PAL',
    'DEFAULT.XMI',
    'H3SHAD.IFR',
  ]);
}

function do_bmp() {
  $ds = DIRECTORY_SEPARATOR;
  $bmp = __DIR__.DIRECTORY_SEPARATOR.'BMP';
  echo <<<ECHO

=>  1. Run tools{$ds}MMArchive{$ds}MMArchive.exe
    2. Call File > Open
    3. Navigate to the folder with installed HoMM 3
    4. Navigate to Data subfolder
    5. Select H3bitmap.lod, then click Open
    6. Click on any file name in the large list on the right (e.g. BIGFONT.FNT)
    7. Press Ctrl+A to select everything
    8. Call Edit > Extract To...
    9. Navigate into BMP subfolder in Workbench (below), then click Save:
       $bmp
   10. Wait until MMArchive stops hanging
   11. Run update.php again

ECHO;
}

function done_bmp2png() {
  return checkFiles('BMP-PNG/', [
    'bitmap.css',
    'TRESBAR-blue.png',
    'CRSTKPU-tan.png',
  ]) and checkFileCopies('BMP', '.bmp', 'BMP-PNG/', '.png');
}

function do_bmp2png() {
  if (!pending('bmp')) {
    return run([true, 'core/databank/bmp2png.php', 'BMP', 'BMP-PNG']);
  }
}

function done_def() {
  return checkFiles('DEF/', [
    'CDEVIL.DEF',
    'AH12_.MSK',
    'MUBHOT.DEF',
    'AVLR4SN0.MSK',
  ]);
}

function do_def() {
  $ds = DIRECTORY_SEPARATOR;
  $def = __DIR__.DIRECTORY_SEPARATOR.'DEF';
  echo <<<ECHO

=>  1. Run tools{$ds}MMArchive{$ds}MMArchive.exe
    2. Call File > Open
    3. Navigate to the folder with installed HoMM 3
    4. Navigate to Data subfolder
    5. Select H3sprite.lod, then click Open
    6. Click on any file name in the large list on the right (e.g. AB01_.DEF)
    7. Press Ctrl+A to select everything
    8. Call Edit > Extract To...
    9. Navigate into DEF subfolder in Workbench (below), then click Save:
       $def
   10. Wait until MMArchive stops hanging
   11. Run update.php again

ECHO;
}

function done_defCustom() {
  return checkFileCopies('core/custom-graphics/DEF', '.def', 'DEF/', '.def');
}

function do_defCustom() {
  if (!pending('def')) {
    foreach (scandir($path = 'core/custom-graphics/DEF') as $file) {
      if (!strcasecmp(substr($file, -4), '.def')) {
        copy("$path/$file", "DEF/$file");
      }
    }
    return true;
  }
}

function done_defExtracted() {
  $res = checkDefExtracted();

  if (!$res['missing'] and !$res['missing_t'] and !$res['missing_e'] and
      !$res['missing_x']) {
    array_map('unlink', $res['touched']);

    return checkFiles('DEF-extracted/', [
      'CPHX\CPHX.h3l',
      'CPHX\CPHX.hdl',
      'CPHX\cphx04.bmp',
      'CPHX\Shadow\cphx66.bmp',

      'SNOWTL\SNOWTL.h3l',
      'SNOWTL\SNOWTL.hdl',
      'SNOWTL\tsns043.bmp',
    ]);
  }
}

function checkDefExtracted() {
  static $result;

  if (!$result) {
    $def = __DIR__.DIRECTORY_SEPARATOR.'DEF';
    $extracted = __DIR__.DIRECTORY_SEPARATOR.'DEF-extracted';
    $touched = $missing = $missing_t = $missing_e = $missing_x = [];

    foreach (scandir($def, SCANDIR_SORT_NONE) as $file) {
      if (!strcasecmp(substr($file, -4), '.def')) {
        $file = substr($file, 0, -4);

        if (!is_dir($full = "$extracted/$file")) {
          $missing[] = $file;
        } else {
          $mark = function ($hotkey, $ready)
              use ($file, $full, &$touched, &$missing_t, &$missing_e, &$missing_x) {
            $okFile = "$full/ok-$hotkey";
            if ($ready) {
              writeFile($okFile, '');
              $touched[] = $okFile;
            } else {
              is_file($okFile) and unlink($okFile);
              ${"missing_$hotkey"}[] = $file;
            }
          };

          $bmp = null;
          $dir = opendir($full);
          while (!$bmp and false !== $f = readdir($dir)) {
            strcasecmp(substr($f, -4), '.bmp') or $bmp = $f;
          }
          closedir($dir);

          // "Extract All for DefTool" creates .hdl, Shadow\ (optional) and .bmp
          // without palette.
          $mark('t', $t = ($bmp and is_file("$full/$file.hdl")));

          // "Extract Picture(s)" creates .bmp with palette.
          // If "Extract All for DefTool" ($t) is to be run, "Extract
          // Picture(s)" must be run after it to overwrite non-Shadow .bmp.
          $mark('e', $t and imagecolorstotal(imagecreatefrombmp("$full/$bmp")));

          // "Export Defmaker DefList" creates .h3l (first line in new format is
          // a number).
          $ready = false;
          try {
            $h = fopen("$full/$file.h3l", 'rb');
            $ready = is_numeric(rtrim(fgets($h), "\r\n"));
            fclose($h);
          } catch (Throwable $e) {}
          $mark('x', $ready);
        }
      }
    }

    $result = compact('def', 'extracted', 'touched', 'missing',
                      'missing_t', 'missing_e', 'missing_x');
  }

  return $result;
}

function do_defExtracted() {
  if (!pending('def')) {
    extract(checkDefExtracted(), EXTR_SKIP);
    $missingC = count($missing);
    $ds = DIRECTORY_SEPARATOR;

    $commands = [
      't' => 'Extract All for DefTool',
      'e' => 'Extract Pictures',
      'x' => 'Export Defmaker DefList',
    ];

    copy('tools/AutoHotkeyU32.exe', 'tools/DefPreview/AutoHotkeyU32.exe');

    foreach ($commands as $hotkey => $name) {
      $ahk = "\xEF\xBB\xBF".<<<AHK
Loop Files, $def\\*.def
{
  SplitPath, A_LoopFileName, , , , basename,
  IfExist, $extracted\%basename%\ok-$hotkey
    Continue
  ; DefPreview on WinXP automatically creates subfolder in last used
  ; export's directory but Win10's open dialog doesn't allow that.
  FileCreateDir, $extracted\%basename%
  Run, DefPreview.exe %A_LoopFileFullPath%, , , pid
  WinWaitActive, ahk_pid %pid%
  ; DefPreview doesn't create Shadow$ds for type $45.
  IfEqual, basename, ADAG, Send !O, n
  Send !E, $hotkey
  ; Not localized in DefPreview.
  WinWaitActive, Сохранение
  Send {Enter}
  IfEqual, basename, ADAG, Send !O, n
  Send !F, x
}
AHK;
      writeFile("$name.ahk", $ahk);
      writeFile("tools/DefPreview/$name.ahk", $ahk);
    }

    echo <<<ECHO

=> Extracting DEFs is currently semi-manual (and arguably troublesome).
   The best approach is using AutoHotKey + DefPreview.
   Use only DefPreview 1.0.0, not later versions (they don't export Shadow$ds).

   >> Warning: once you run AutoHotKey, you won't be able to do anything on
   >> your PC for an hour or longer, unless you use a virtual machine.

   >> To pause, repeatedly click on an empty desktop or taskbar space until
   >> you see DefPreview window appearing but not becoming active (focused).
   >> To resume, activate that window by clicking on it.
   >> To stop, right-click on the green "H" icon in the taskbar (near clock)
   >> and call Exit.

   Part I. Extract All for DefTool

    1. Run tools{$ds}DefPreview{$ds}DefPreview.exe
    2. Call Options > Language > English
    3. Call File > Open
    4. Navigate to the DEF subfolder where you have extracted H3sprite.lod to:
       $def
    5. Select any DEF file (e.g. AB01_.DEF), then click Open
    6. Call Edit > Extract All for DefTool
    7. Navigate into DEF-extracted subfolder in Workbench (below), then click Save:
       $extracted
    8. Exit DefPreview

    9. If not already there, copy "Extract All for DefTool.ahk" from Workbench to the folder with DefPreview.exe
   10. Drag & drop the copied file onto AutoHotkeyU32.exe
   11. Wait until windows stop flashing
   12. Close DefPreview windows that remained opened

   Part II. Extract Picture(s)

    This will overwrite some of the files produced by "Extract All for DefTool".

    1. Run DefPreview
    2. Check Options > No Shadow & Selection
    3. Uncheck Options > Extract In 24 Bits (if your DefPreview doesn't have this, you're good)
    4. Exit DefPreview

    5. If not already there, copy "Extract Pictures.ahk" from Workbench to the folder with DefPreview.exe
    6. Drag & drop the copied file onto AutoHotkeyU32.exe
    7. Wait until windows stop flashing
    8. Close DefPreview windows that remained opened

   Part III. Export Defmaker DefList

    1. Run DefPreview and open any DEF file as in Part I
    2. Call Edit > Export Defmaker DefList
    3. Make sure File Type combobox says "Defmaker DefList (H3L)", not "Old Format"
    4. Navigate into DEF-extracted subfolder (that is no longer empty), then click Save:
       $extracted
    5. Exit DefPreview

    6. If not already there, copy "Export Defmaker DefList.ahk" from Workbench to the folder with DefPreview.exe
    7. Drag & drop the copied file onto AutoHotkeyU32.exe
    8. Wait until windows stop flashing
    9. Close DefPreview windows that remained opened

   Part IV. Fix missing files

    1. After doing all of the above, run update.php again
    2. Just checked - there are $missingC files missing

ECHO;

    if ($missing_t or $missing_e or $missing_x) {
?>
<?php if ($missing_t) {?>
       + <?=count($missing_t)?> pending "Extract All for DefTool"
<?php }?>
<?php if ($missing_e) {?>
       + <?=count($missing_e)?> pending "Extract Picture(s)"
         (if this number doesn't change, check Options as told in Part II)
<?php }?>
<?php if ($missing_x) {?>
       + <?=count($missing_x)?> pending "Export Defmaker DefList"
         (if this number doesn't change, check File Type as told in Part III)
<?php }?>
    3. Fix that by re-running DefPreview (it will skip already processed files):
<?php if ($missing_t) {?>
       a. Drag & drop "Extract All for DefTool.ahk" onto AutoHotkeyU32.exe
<?php }?>
<?php if ($missing_e) {?>
       b. Drag & drop "Extract Pictures.ahk" onto AutoHotkeyU32.exe
<?php }?>
<?php if ($missing_x) {?>
       c. Drag & drop "Export Defmaker DefList.ahk" onto AutoHotkeyU32.exe
<?php }?>
       d. Run update.php again
       e. Repeat step IV.3 until this message disappears
<?php
      foreach ($commands as $hotkey => $name) {
        if ($count = count(${"missing_$hotkey"}) and $count <= 10) {
          printf(PHP_EOL);
          printf('%d DEF pending "%s":%s', $count, $name, PHP_EOL);
          foreach (${"missing_$hotkey"} as $file) {
            printf(  '%s%s', $file, PHP_EOL);
          }
        }
      }
    }
  }
}

function done_def2png() {
  foreach (scandir($path = 'DEF-extracted') as $file) {
    if ($file[0] !== '.' and is_file("$path/$file/$file.hdl") and
        !is_file("DEF-PNG/$file/texture.json")) {
      return false;
    }
  }

  return checkFiles('DEF-PNG/', [
    '_SGNCTW1\activeTurn-2-7.png',
    '_SGNCTW1\animation.css',
    '_SGNCTW1\button.css',
    '_SGNCTW1\def.css',
    '_SGNCTW1\hover-1-10.png',
    '_SGNCTW1\texture.json',

    'AB01_\4-2.png',
    'AB01_\animation.css',
    'AB01_\blueOwner-0-7.png',
    'AB01_\button.css',
    'AB01_\def.css',
    'AB01_\greenOwner-9-0.png',
    'AB01_\texture.json',

    'AH00_\0-7.png',
    'AH00_\animation.css',
    'AH00_\button.css',
    'AH00_\def.css',
    'AH00_\orangeOwner-1-7.png',
    'AH00_\texture.json',

    'AH00_E\0-0.png',
    'AH00_E\button.css',
    'AH00_E\def.css',
    'AH00_E\purpleOwner-0-0.png',
    'AH00_E\texture.json',

    'AVGERTH0\0-1.png',
    'AVGERTH0\animation.css',
    'AVGERTH0\button.css',
    'AVGERTH0\def.css',
    'AVGERTH0\redOwner-0-7.png',
    'AVGERTH0\texture.json',

    'AVWHALF\0-21.png',
    'AVWHALF\animation.css',
    'AVWHALF\button.css',
    'AVWHALF\def.css',
    'AVWHALF\texture.json',

    'IAM002\0-2.png',
    'IAM002\animation.css',
    'IAM002\button.css',
    'IAM002\def.css',
    'IAM002\pink-0-0.png',
    'IAM002\tan-0-3.png',
    'IAM002\texture.json',
  ]);
}

function do_def2png() {
  if (!pending('bmp') and !pending('bmp2png') and !pending('defCustom') and
      !pending('defExtracted')) {
    $cmd = [
      'core/databank/def2png.php',
      'DEF-extracted',
      'DEF-PNG',
      '-p', 'BMP\PLAYERS.PAL',
      '-b', 'BMP-PNG',
      '-K',
    ];

    $count =
      // Windows.
      getenv('NUMBER_OF_PROCESSORS') ?:
      // Linux.
      exec('2>/dev/null nproc') ?:
      exec("2>/dev/null grep -m1 ^siblings /proc/cpuinfo | grep -oG '[[:digit:]]\+'") ?:
      // BSD.
      exec('sysctl -n hw.ncpu');

    $count = min($count - 1, 12);

    if ($count > 0) {
      printf('=> Your CPU seems to have %d cores. DEF convertion may take over an hour if done in a single thread. We can launch up to %d copies of def2png.php to speed it up.%s', $count + 1, $count, PHP_EOL);
      printf(PHP_EOL);
      printf("   Launching more than one copy %s, and update.php will quit. You'll have to watch when all of them exit, and restart update.php.%s", WINDOWS ? 'will create multiple console windows' : 'will start several background& tasks managed by your shell (with intermixing outputs)', PHP_EOL);
      printf(PHP_EOL);

      do {
        printf('   Type how many copies to launch (1..%d), then press Enter, or just press Enter to go with %d: ', $count, $count);
        $s = trim(readSTDIN());
        $cap = $s === '' ? $count : (int) $s;
      } while ($cap <= 0 or $cap > $count);
    }

    printf(PHP_EOL);

    if ($cap > 1) {
      array_unshift($cmd, PHP_BINARY);
      $cmd = array_map('escapeshellarg', $cmd);

      printf('Command line (# = thread, 1..%d):%s', $cap, PHP_EOL);
      printf('%s -m #/%d%s', join(' ', $cmd), $cap, PHP_EOL);
      printf('cd %s%s', getcwd(), PHP_EOL);
      printf(str_repeat('-', 78).PHP_EOL);

      if (WINDOWS) {
        array_unshift($cmd, 'start "def2png"');
      } else {
        $cmd[] = '&';
      }

      foreach (range(1, $cap) as $thread) {
        pclose(popen(join(' ', $cmd)." -m $thread/$cap", 'w'));
        usleep(100000);
      }
    } else {
      return run(array_merge([true], $cmd));
    }
  }
}

function done_mp3() {
  return checkFiles('MP3/', [
    'CstleTown.mp3',
    'Surrender Battle.mp3',
    'BladeABCampaign.mp3',
    'Lose Campain.mp3',
  ]);
}

function do_mp3() {
  $mp3 = __DIR__.DIRECTORY_SEPARATOR.'MP3';
  echo <<<ECHO

=> 1. Open an Explorer window at the folder with installed HoMM 3
   2. Copy content of the MP3 subfolder into MP3 subfolder in Workbench (below):
      $mp3
   3. Run update.php again

ECHO;
}

function done_wav() {
  return checkFiles('WAV/', [
    'HALFMOVE.WAV',
    'REGENER.WAV',
    'WATRWALK.WAV',
    'OBELISK.WAV',
    'IMPPKILL.WAV',
  ]);
}

function do_wav() {
  $ds = DIRECTORY_SEPARATOR;
  $wav = __DIR__.DIRECTORY_SEPARATOR.'WAV';
  echo <<<ECHO

=>  1. Run tools{$ds}MMArchive{$ds}MMArchive.exe
    2. Call File > Open
    3. Navigate to the folder with installed HoMM 3
    4. Navigate to Data subfolder
    5. Select Heroes3.snd, then click Open
    6. Click on any file name in the large list on the right (e.g. AAGLATTK)
    7. Press Ctrl+A to select everything
    8. Call Edit > Extract To...
    9. Navigate into WAV subfolder in Workbench (below), then click Save:
       $wav
   10. Wait until MMArchive stops hanging
   11. Run update.php again

ECHO;
}

function done_wav2pcm() {
  return checkFiles('WAV-PCM/', [
    'MGRMATTK.WAV',
    'AMAGDFND.WAV',
    'FIRESTRM.WAV',
    'STORM.WAV',
    'PICKUP02.WAV',
  ]) and checkFileCopies('WAV', '.wav', 'WAV-PCM/', '.wav');
}

function do_wav2pcm() {
  if (!pending('wav')) {
    $program = 'tools'.DIRECTORY_SEPARATOR.'adpcm2pcm'.DIRECTORY_SEPARATOR.'adpcm2pcm';
    exec($program, $output, $code);
    $found = stripos(join($output), 'DVI-ADPCM') !== false && $code === 1;

    printf('adpcm2pcm ... %s%s', $found ? 'found' : 'MISSING', PHP_EOL);

    if (!$found) {
      printf(PHP_EOL);
      printf("=> For some reason, adpcm2pcm can't run on your system.");
      if (WINDOWS) {
        printf(' Try launching %s via Explorer to see the error message.%s',
          $program, PHP_EOL);
      } else {
        printf(' Try to recompile it:%s', PHP_EOL);
        printf('gcc -o %s %s.c%s', $program, $program, PHP_EOL);
      }
      return;
    }

    printf(PHP_EOL);
    is_dir('WAV-PCM') or mkdir('WAV-PCM');
    return convertFolder('WAV', '.wav', 'WAV-PCM', '.wav', [$program, NAN, INF]);
  }
}

function done_wav2ogg() {
  return checkFiles('WAV-OGG/', [
    'AZURKILL.ogg',
    'DEVLMOVE.ogg',
    'RUSTATTK.ogg',
    'SGRGMOVE.ogg',
    'WRTHWNCE.ogg',
  ]) and checkFileCopies('WAV-PCM', '.wav', 'WAV-OGG/', '.ogg');
}

function do_wav2ogg() {
  if (!pending('wav2pcm')) {
    foreach (['oggenc2', 'oggenc'] as $file) {
      if (stripos(exec("$file --version", $output, $code), 'vorbis') !== false and !$code) {
        $program = $file;
        break;
      }
    }

    printf('oggenc/oggenc2 ... %s%s', empty($program) ? 'MISSING' : 'found', PHP_EOL);

    if (empty($program)) {
      printf(PHP_EOL);
      printf('=> Install vorbis-tools using your package manager and try again.%s', PHP_EOL);
      return;
    }

    printf(PHP_EOL);
    return convertFolder('WAV-PCM', '.wav', 'WAV-OGG', '.ogg',
      [$program, '-Q', '-q', 7, '-o', INF, NAN]);
  }
}

function done_databank() {
  return checkFiles('databanks/'.currentDatabank().'/', [
    'combined.json',
    'combined.css',
    'menu.css',
    'buttons.css',
    'spellSchoolsID.json',
  ]);
}

function do_databank() {
  if (!pending('bmp') and !pending('bmp2png') and !pending('mp3') and
      !pending('wav2ogg') and !pending('def2png')) {
    $cmd = <<<CMD
-d memory_limit=1G
core/databank/databank.php
-p -t BMP -g core/databank/shapes.json
-d DEF-PNG -du ../../DEF-PNG/
-a MP3 -a WAV-OGG -au ../../
-b BMP-PNG/bitmap.css -bu ../../BMP-PNG/
CMD;

    $cmd = array_merge([true], preg_split('/\\s+/', $cmd), [
      '-v', currentDatabank(), 'databanks',
    ]);

    return run($cmd);
  }
}

function done_h3m() {
  return checkFiles('H3M/', [
    'Tutorial.tut',
    'Xathras Prize.h3m',
    "Monk's Retreat.h3m",
    'Ascension.h3m',
    'Good Witch, Bad Witch.h3m',
    'Back For Revenge - Allied.h3m',
  ]);
}

function do_h3m() {
  $h3m = __DIR__.DIRECTORY_SEPARATOR.'H3M';
  echo <<<ECHO

=> 1. Open an Explorer window at the folder with installed HoMM 3
   2. Copy content of the Maps subfolder into H3M subfolder in Workbench (below):
      $h3m
   3. Run update.php again

ECHO;
}

function done_tutorial() {
  return checkFiles('maps/Tutorial/', [
    'map.json',
    'combined.json',
    'eLabel.json',
    'spot.json',
  ]);
}

function do_tutorial() {
  if (!pending('databank') and !pending('h3m')) {
    if (config('h3m2herowo') === null) {
      require_once 'core/databank/h3m2json/h3m2json.php';

      printf("=> What language your HoMM 3 maps are in? Maps in English and in this one other language will be held in H3M%s.%s", DIRECTORY_SEPARATOR, PHP_EOL);
      printf(PHP_EOL);
      printf("   You'll be able to convert maps in other languages separately: update.php some%smap.h3m -s LANG%s", DIRECTORY_SEPARATOR, PHP_EOL);
      printf(PHP_EOL);

      do {
        printf('   Type one of %s, then press Enter, or just press Enter for English (en): ',
          join(' ', array_keys(HeroWO\H3M\CLI::$charsets)));
        $s = strtolower(trim(readSTDIN()));
        $s === '' and $s = 'en';
      } while (!isset(HeroWO\H3M\CLI::$charsets[$s]));

      config('h3m2herowo', $s === 'en' ? [] : ['-s', $s]);
    }

    return h3m2herowo('H3M/Tutorial.tut', ['-ew', '-ih']);
  }
}

function done_maps() {
  $findMissing = function ($path) use (&$findMissing) {
    foreach (scandir("H3M/$path", SCANDIR_SORT_NONE) as $file) {
      if ($file === '.' or $file === '..') {
        // No party tonight.
      } elseif (is_dir("H3M/$path/$file")) {
        if ($findMissing("$path/$file")) { return true; }
      } elseif (!strcasecmp(substr($file, -4), '.h3m')) {
        $full = "maps/$path/".substr($file, 0, -4);
        $files = ['map.json', 'combined.json', 'eLabel.json', 'spot.json'];
        foreach ($files as $f) {
          if (!is_file("$full/$f")) { return true; }
        }
      }
    }
  };

  return checkFiles('maps/', [
    'Tutorial/combined.json',
    'Battle of the Sexes/map.json',
    "Monk's Retreat/original.h3m",
    'Good Witch, Bad Witch/objects.txt',
    'Back For Revenge - Allied/type.json',
  ]) and  !$findMissing('');
}

function do_maps() {
  global $SKIP_PENDING;
  if (!pending('databank') and !pending('h3m') and !pending('tutorial')) {
    return h3m2herowo('H3M', empty($SKIP_PENDING) ? ['-nx', '-ei'] : ['-ei']);
  }
}

function done_bik() {
  return config('ignoreBIK');
}

function do_bik() {
  printf("=> HeroWO is using HoMM 3 videos in a few parts of the game's interface (for example, in Tavern and Combat Results). Obtaining these are troublesome at this time - but their absence won't hinder normal operation.%s", PHP_EOL);
  printf(PHP_EOL);
  printf('   You can leave them absent for now, perform the convertion yourself based on the information in core/databank/Formats.txt, or download the ready-made files from herowo.io/dl%s', PHP_EOL);
  printf(PHP_EOL);
  printf('   Press Enter to continue');

  readSTDIN();
  config('ignoreBIK', true);
  return true;
}
