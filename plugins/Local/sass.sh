#!/usr/bin/env bash

# for sass-dart
sass --no-source-map --style=compressed webroot/css/src/theme.scss webroot/css/theme.css;
sass --no-source-map --style=compressed webroot/css/src/night.scss webroot/css/night.css;

# Strip unused (mostly Bootstrap) selectors — see purgecss.config.js.
npx --yes purgecss@5 --config purgecss.config.js --output webroot/css/;
