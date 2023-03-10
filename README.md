# HeroWO's Workbench

> — It’s dangerous to go alone! Take this

HeroWO is a JavaScript re-implementation of *Heroes of Might and Magic III*.

This repository sets up a workspace for running HeroWO game client and generating databanks and maps.

https://github.com/HeroWO-js

https://herowo.game

## Get to start

First off, install **git**, PHP (7+) and any web server. On \*nix, you'll have all the stuff easily available via apt/yum/pacman/whatever. On Windows, installing [Git for Windows](https://github.com/git-for-windows/git/releases/), [TortoiseGit](https://tortoisegit.org/download/) (optional) and [XAMPP](https://sourceforge.net/projects/xampp/files/latest/download) will get you everything needed. XAMPP can be installed with all optional components unchecked.

## Clone it deeply

Do not use GitHub's code download function because the archive it generates lacks the required submodules.

Instead, open Command Prompt, `cd` into your web server's document root (typically `/var/www` on \*nix, `C:\xampp\htdocs` on Windows) and type this:

```
git clone --recurse-submodules git@github.com:HeroWO-js/Workbench.git
```

Alternatively, if you have installed **TortoiseGit**: open Explorer, right-click on the document root or on any folder inside it, call `Git Clone...`, paste `git@github.com:HeroWO-js/Workbench.git` into `URL`, tick `Recursive`, leave other settings at their defaults and click OK.

## Check the structure

The `Workbench` folder will have a bunch of subfolders, most initially absent.

You must see the `core` subfolder (the game engine itself; https://github.com/HeroWO-js/Core) and it must be filled with files - if not then you didn't initialize submodules as explained above. Game over, try again!

You'll also see `update.php` - the script populating various subfolders and making it easier to update the data later with less typing in the terminal.

Another one, `optimize-png.sh`, reduces size of static images by converting them to WebP or by running `oxipng`. You'd typically execute it on your own game client hosting server.

Eventually, you'll get:

* subfolders with game animations, images and audio - totalling about 1 GiB, but you'll need about 7 more GiB during initial convertion
* temporary subfolders used during initialization - `update.php` will suggest removing them once done
* `databanks` subfolder - game data in HeroWO format, extracted from HoMM 3 files and possibly modded
* `maps` subfolder - maps in HeroWO format, usually converted from HoMM 3 maps (`.h3m`)
  * this will take 11+ GiB for all official maps but it'll come down by 90% if you enable file system compression (`update.php` will advise)
* several `*.ahk` scripts - help extracting DEF frames (can be deleted)

By convention, folders with data coming from HoMM 3 are named after the data's original file extension in upper-case, optionally followed by `-` and the converted extension (e.g. `WAV-OGG`).

## Boot'n'strap

Once you're all set, type this:

```
php path/to/update.php
```

On Windows, you may be able to just double-click `update.php` to run it. Or click the Shell button in XAMPP Control Panel and type the line above.

Follow the script's interactive instructions to finish configuring your local environment (or run `update.php -h` for options). It may take a while, be patient even if nothing seems to be happening on screen!

Instructions for manual convertion are found in `update.php`'s source code, `convertH3Data()` function's comment.
