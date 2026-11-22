#!/usr/bin/env bash
##
# Copy the PHPStan baseline into the build.
#
# `phpstan.neon` includes `phpstan-baseline.neon`, and the tooling copies
# only the tool configuration files it knows by name, so without this the
# build has no baseline and PHPStan stops on the missing include. The
# working directory is the project root.
#

set -eu

if [ ! -f phpstan-baseline.neon ]; then
  exit 0
fi

cp phpstan-baseline.neon build/phpstan-baseline.neon
