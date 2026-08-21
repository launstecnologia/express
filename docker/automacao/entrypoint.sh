#!/bin/sh
set -e

DISPLAY_NUM="${DISPLAY#:}"
DISPLAY_NUM="${DISPLAY_NUM:-99}"
export DISPLAY=":${DISPLAY_NUM}"

if [ ! -e "/tmp/.X${DISPLAY_NUM}-lock" ]; then
  Xvfb "$DISPLAY" -screen 0 1366x768x24 -ac -nolisten tcp >/tmp/xvfb.log 2>&1 &
  i=0
  while [ "$i" -lt 30 ]; do
    if [ -e "/tmp/.X${DISPLAY_NUM}-lock" ]; then
      break
    fi
    i=$((i + 1))
    sleep 0.1
  done
fi

exec uvicorn api:app --host 0.0.0.0 --port 8001 --workers 1 --reload
