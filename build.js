// This is a configuration file for require.js' optimizer.
({
  mainConfigFile: 'core/client/config.js',
  paths: {
    rjs: 'r.js/require'
  },
  // Difference in size of output of bundled requirejs' uglify and uglify 3.16.2
  // is 1-2%.
  //
  // XXX Consider Closure Compiler's ADVANCED_OPTIMIZATIONS that may have very
  // significant output size advantage over uglify.
  //optimize: 'none',
  generateSourceMaps: true,
  uglify: {
    compress: true,
    warnings: true,
    mangle: true
  },
  useStrict: true,
  // When changing here, also change in index.php.
  namespace: 'H',
  // H3 is require()'d dynamically by Context based on Map's modules.
  include: ['rjs', 'H3', 'Entry.Browser', 'Entry.Worker'],
  // update.php overrides this via CLI options.
  //out: 'herowo.min.js',
  onBuildRead: function (moduleName, path, contents) {
    // The minified script also acts as a Worker source; however, there's no DOM
    // access in there and jQuery fails to initialize (even though Worker code
    // doesn't actually use jQuery).
    if (moduleName == 'jquery') {
      contents = 'if (self.document) ' + contents
    }
    // Replace UMH with a direct define() call. Not only this reduces the output
    // size slightly but also makes the optimizer discover UMH's deps (e.g.
    // jquery as included by sqimitive/jquery.js) without us listing them in
    // include.
    if (contents.substr(0, 2000).includes('// --- Universal Module')) {
      var deps = contents.match(/^  var deps = (['"])(.*)\1\s*$/m)
      deps = deps[2].replace(/\?/g, '/').match(/\S+(?=:)/g)
      var pos = contents.indexOf('}).call(this') + 12
      contents = 'define(' + JSON.stringify(deps || []) + contents.substr(pos)
    }
    return contents
  },
  throwWhen: {
    optimize: true
  }
})