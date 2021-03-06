const webpack = require('webpack');
const path = require('path');
const MiniCssExtractPlugin = require('mini-css-extract-plugin');
const devMode = process.env.NODE_ENV !== 'production';

module.exports = {
  mode: devMode ? 'development' : 'production',
  devtool: devMode && 'source-map',
  entry: {
    main: [
      path.resolve(__dirname, 'src', 'index.js')
    ],
    '../ServiceWorker': path.resolve(__dirname, 'src', 'Workers', 'ServiceWorker.js'),
    'Workers/ResizeImage': path.resolve(__dirname, 'src', 'Workers', 'ResizeImage.js'),
    'Workers/AESEncryption': path.resolve(__dirname, 'src', 'Workers', 'AESEncryption.js')
  },
  output: {
    path: path.resolve(__dirname),
    filename: 'dist/[name].js'
  },
  plugins: [
    new MiniCssExtractPlugin({
      filename: 'dist/[name].css',
      chunkFilename: 'dist/[id].css',
    }),
    // load `moment/locale/ja.js` and `moment/locale/it.js`
    new webpack.ContextReplacementPlugin(/moment[/\\]locale$/, /en/),
    new webpack.DefinePlugin({
      'process.env': {
        'NODE_ENV': devMode || JSON.stringify('production')
      }
    })
  ],
  resolve: {
    mainFields: ['svelte', 'browser', 'module', 'main']
  },
  module: {
    rules: [
      {
        test: /\/src\/Services\/Val\/.+\.js$/,
        use: {
          loader: 'val-loader'
        }
      },
      {
        test: /\.(html|svelte)$/,
        use: {
          loader: 'svelte-loader',
          options: {
            emitCss: true,
          }
        }
      },
      {
        test: /\.(s[ac]|c)ss$/,
        use: [
          devMode ? 'style-loader' : MiniCssExtractPlugin.loader,
          {
            loader: 'css-loader',
            options: {
              minimize: !devMode
            }
          },
          'sass-loader'
        ]
      },
      {
        exclude: /\/node_modules\/localforage\//,
        test: /\.js$/,
        use: {
          loader: 'babel-loader'
        }
      }
    ]
  }
};
