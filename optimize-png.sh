#!/bin/bash
set -e -o pipefail
DEFAULT_DIRS='BMP-PNG DEF-PNG'

if test "$1" = -h; then
  echo "Usage: `basename "$0"` [ [-png] [-D|-Z] | -webp ] [file.png [dir/ [...]]]"
  echo "By default, using -png -D $DEFAULT_DIRS, recursively."
  echo '-png requires oxipng, -webp requires libwebp (cwebp).'
  echo '-png overwrites the original files, -webp writes new to .png.webp.'
  echo '-D uses Libdeflater, -Z uses Zopfli (marginally smaller output, extremely slow).'
  echo '-webp is smaller by ~10% (gain is best on very small images).'
  echo 'Configure your web server to internally redirect requests based on'
  echo 'Accept: image/webp to .png.webp and to send proper Content-Type.'
  exit 1
fi

FORMAT=$(grep -oG '^-\(png\|webp\)$' <<<$1 || true)
if test -z "$FORMAT"; then
  FORMAT=-png
else
  shift
fi

if test "$FORMAT" = -png; then
  PROG=oxipng
  MODE=$(grep -oG '^-[DZ]$' <<<$1 || true)
  if test -z "$MODE"; then
    MODE=-D
  else
    shift
  fi
elif test "$FORMAT" = -webp; then
  PROG=cwebp
fi

if test $# -eq 0; then
  set $DEFAULT_DIRS
fi

if test $PROG = oxipng; then
  $PROG -omax -rpsa $MODE -- $*
elif test $PROG = cwebp; then
  # As find doesn't have an equivalent of '--', making sure no paths find
  # receives start with a dash, as suggested by man find.
  realpath -sz -- $* | find -files0-from - -type f -iname \*.png -print0 \
    | xargs -0 -I% $PROG -lossless -z 9 -mt -short -o %.webp -- %
fi
