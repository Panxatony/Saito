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
          /// Assets for plugins/SprectrumColorpicker
          {
            src: './node_modules/spectrum-colorpicker/spectrum.js',
            dest: './plugins/SpectrumColorpicker/webroot/js/spectrum.js',
          },
          {
            src: './node_modules/spectrum-colorpicker/spectrum.css',
            dest: './plugins/SpectrumColorpicker/webroot/css/spectrum.css',
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
    uglify: {
      release: {
        files: {
          // './webroot/dist/main.min.js': ['./webroot/dist/main.min.js']
        }
      }
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
    'dart-sass': {
      options: {
        sourceComments: true,
        sourceMap: false,
        // compression is done by "postcss"-task
        // outputStyle: 'compressed',
      },
      static: {
        files: {
          // The admin stylesheet is not built here: it moved into the plugin
          // with the Bootstrap 4 rewrite and is loaded as 'Admin.admin.css'.
          // This entry named a source that has not existed since, and
          // grunt-dart-sass skips a missing file without a word.
          'webroot/css/stylesheets/static.css': 'webroot/css/src/static.scss',
        }
      },
      theme: {
        files: {
          'plugins/Bota/webroot/css/night.css': 'plugins/Bota/webroot/css/src/night.scss',
          'plugins/Bota/webroot/css/theme.css': 'plugins/Bota/webroot/css/src/theme.scss',
          // Nova (the modern default) builds on Bota's partials, so it is
          // compiled from the same task and rebuilt whenever Bota changes.
          'plugins/Nova/webroot/css/night.css': 'plugins/Nova/webroot/css/src/night.scss',
          'plugins/Nova/webroot/css/theme.css': 'plugins/Nova/webroot/css/src/theme.scss',
          // Macnemo imports Nova, which imports Bota's partials — but it was
          // missing here, so every change to those partials stopped at Nova and
          // the macnemo theme silently drifted away from its own source.
          'plugins/Macnemo/webroot/css/night.css': 'plugins/Macnemo/webroot/css/src/night.scss',
          'plugins/Macnemo/webroot/css/theme.css': 'plugins/Macnemo/webroot/css/src/theme.scss',
        }
      },
    },
    watch: {
      sassStatic: {
        files: ['webroot/css/src/**/*.scss'],
        tasks: ['dart-sass:static'],
      },
      sassTheme: {
        files: [
            'plugins/Bota/webroot/css/src/**/*.scss',
            'plugins/Nova/webroot/css/src/**/*.scss',
            'plugins/Macnemo/webroot/css/src/**/*.scss',
          ],
        tasks: ['dart-sass:theme'],
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
          require('autoprefixer')({ browsers: 'last 2 versions' }), // add vendor prefixes
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
          // Macnemo is compiled by dart-sass:theme but was missing here, so it
          // was the one theme released unprefixed and unminified.
          'plugins/Macnemo/webroot/css/*.css'
        ]
      },
    },
  };

  grunt.initConfig(gruntConfig);

  grunt.loadNpmTasks('grunt-contrib-copy');
  grunt.loadNpmTasks('grunt-contrib-uglify-es');
  grunt.loadNpmTasks('grunt-contrib-clean');
  grunt.loadNpmTasks('grunt-contrib-watch');
  grunt.loadNpmTasks('grunt-shell');
  grunt.loadNpmTasks('grunt-dart-sass');
  grunt.loadNpmTasks('grunt-postcss');

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
    'dart-sass:static',
    'dart-sass:theme',
    'postcss:release',
    // JS bundle (Vite)
    'shell:bundle',
    // JS
    'copy:nonmin',
    'uglify:release',
    // l10n
    // cleanup
    'clean:releasePost'
  ]);
};
