/*jshint node: true */
process.env.TZ = 'Europe/Berlin';

module.exports = function (grunt) {
  'use strict';

  var gruntConfig = {
    pkg: grunt.file.readJSON('package.json'),
    copy: {
      nonmin: { // non minified files needed for debug modus
        files: [
          // CSS (+ sourcemap so DevTools have something to load instead of 404)
          {
            expand: true,
            flatten: true,
            src: [
              './node_modules/bootstrap/dist/css/bootstrap.min.css',
              './node_modules/bootstrap/dist/css/bootstrap.min.css.map',
            ],
            dest: './webroot/css/stylesheets/',
          },
          // font-awesome fonts
          {
            expand: true,
            cwd: './node_modules/font-awesome/fonts/',
            src: '*',
            dest: './webroot/css/stylesheets/fonts/'
          },
          /// Assets Cabin font
          {
            expand: true,
            flatten: true,
            src: './node_modules/typeface-cabin/files/cabin-latin-[4|7]00.woff*',
            dest: './plugins/Bota/webroot/fonts/',
          },
          {
            expand: true,
            flatten: true,
            src: './node_modules/typeface-cabin/files/cabin-latin-[4|7]00italic.woff*',
            dest: './plugins/Bota/webroot/fonts/',
          },
          /// Assets Fenix font
          {
            expand: true,
            flatten: true,
            src: './node_modules/typeface-fenix/files/fenix-latin-400.woff*',
            dest: './plugins/Bota/webroot/fonts/',
          },
          /// The licence those two fonts are under. The SIL Open Font License
          /// requires its text to be distributed with the font, and the npm
          /// packages carry only their own packaging licence — so it is kept in
          /// the repository and copied next to the files it covers. Both theme
          /// font directories get a copy, because either can be deployed alone.
          {
            expand: true,
            flatten: true,
            src: './webroot/css/src/fonts/OFL.txt',
            dest: './plugins/Bota/webroot/fonts/',
          },
          {
            expand: true,
            flatten: true,
            src: './webroot/css/src/fonts/OFL.txt',
            dest: './plugins/Macnemo/webroot/fonts/',
          },
        ]
      },
    },
    clean: {
      devsetup: [
        // font-awesome
        './webroot/css/stylesheets/fonts/',
      ],
      release: ['./webroot/js/**/!(empty)'],
      releasePost: ['./webroot/release-tmp']
    },
    shell: {
      // The two locale tasks lived here. They turned frontend/src/locale/*.po
      // into webroot/js/locale/*.json, which only the retired SPA read (via
      // JsDataHelper::getAppJs, reached from the SPA layout). The island
      // translates server-side through PHP's __(), out of src/Locale.
      // Sass through its own CLI rather than grunt-dart-sass, which was last
      // released in May 2022 and drives the legacy JS API that Dart Sass 2
      // removes. It sits in the path that builds the themes for every release,
      // so the day that lands it would not break somebody's local work — it
      // would break the release pipeline, under time pressure.
      //
      // Checked before the swap: the CLI's output is byte-identical to the
      // plugin's but for a trailing newline, which the plugin omitted. The
      // plugin's `sourceComments: true` turned out to emit nothing at all.
      sassStatic: {
        command: 'yarn css:static',
        options: { stdout: true, stderr: true, failOnError: true, },
      },
      sassTheme: {
        command: 'yarn css:theme',
        options: { stdout: true, stderr: true, failOnError: true, },
      },
      // Release only, and deliberately so: what a developer compiles locally is
      // the full stylesheet, and what ships is trimmed. That difference is a
      // hazard worth naming — a rule that only the purge removes will look fine
      // in `yarn css` and be missing on the server. `dev/pixel-diff.sh` is what
      // closes it, and it compares the *purged* output.
      purge: {
        command: 'yarn css:purge',
        options: { stdout: true, stderr: true, failOnError: true, },
      },
      bundle: {
        // JS bundle via Vite (replaces the legacy Webpack 4 build). Emits one
        // self-contained IIFE per entry into webroot/js; no NODE_OPTIONS
        // --openssl-legacy-provider flag required.
        command: 'yarn build',
        options: { stdout: true, stderr: true, failOnError: true, },
      },
      yarn: {
        command: 'yarn',
        options: {
          stdout: true,
          stderr: true,
          failOnError: true
        }
      },
    },
    postcss: {
      options: {
        map: false,
        /*
        map: {
            inline: false, // save all sourcemaps as separate files...
            annotation: 'webroot/css/stylesheets/maps/' // ...to the specified directory
        },
        */
        processors: [
          // The target browsers live in package.json's `browserslist` now:
          // autoprefixer dropped the inline `browsers` option in v10, and a
          // shared list is what cssnano and every other tool reads as well.
          require('autoprefixer')(), // add vendor prefixes
          //// minify the result
          require('cssnano')({
            //// prevents shortening and namespace collision on keyframes names
            // @see https://github.com/ben-eb/gulp-cssnano/issues/33
            // @see https://github.com/ben-eb/cssnano/issues/247
            reduceIdents: {
              keyframes: false
            },
            discardUnused: {
              keyframes: false
            },
          }),
        ]
      },
      release: {
        src: [
          'webroot/css/stylesheets/static.css',
          'plugins/Bota/webroot/css/*.css',
          'plugins/Nova/webroot/css/*.css',
          // Macnemo is compiled by shell:sassTheme but was missing here, so it
          // was the one theme released unprefixed and unminified.
          'plugins/Macnemo/webroot/css/*.css'
        ]
      },
    },
  };

  grunt.initConfig(gruntConfig);

  grunt.loadNpmTasks('grunt-contrib-copy');
  grunt.loadNpmTasks('grunt-contrib-clean');
  grunt.loadNpmTasks('grunt-shell');
  // The maintained fork: `grunt-postcss` was last released in 2018 and speaks
  // only the PostCSS 7 plugin API, so autoprefixer 10 and cssnano 7 arrive as
  // "[object Object] is not a PostCSS plugin".
  grunt.loadNpmTasks('@lodder/grunt-postcss');

  // dev-setup
  grunt.registerTask(
    'dev-setup',
    ['clean:devsetup', 'shell:yarn', 'copy:nonmin']
  );

  // release
  grunt.registerTask('release', [
    // cleanup
    'clean:release',
    // CSS
    'shell:sassStatic',
    'shell:sassTheme',
    'postcss:release',
    // After minification: the compiled themes carry ~1600 classes, the forum
    // uses ~150. Bootstrap stays a dependency; only the shipped CSS is trimmed.
    'shell:purge',
    // JS bundle (Vite)
    'shell:bundle',
    // JS
    'copy:nonmin',
    // l10n
    // cleanup
    'clean:releasePost'
  ]);
};
