const package = require('./package.json');
const path = require('path')
const webpack = require('webpack')
const MiniCssExtractPlugin = require('mini-css-extract-plugin')
const TerserWebpackPlugin = require('terser-webpack-plugin')
const OptimizeCSSAssetsWebpackPlugin = require('optimize-css-assets-webpack-plugin')
const CopyWebpackPlugin = require('copy-webpack-plugin')
const { CleanWebpackPlugin } = require('clean-webpack-plugin')

/**
 * Base webpack configuration
 *
 * @param env -> env parameters
 * @param argv -> CLI arguments, 'argv.mode' is the current webpack mode (development | production)
 * @returns object
 */
module.exports = (env, argv = {}) => {

    let isProduction = (argv.mode === 'production')

    let config = {

        context: path.resolve(__dirname, 'src'),

        entry: [
            './js/index.js',
            './scss/index.scss',
        ],

        // enable development source maps
        // * will be overwritten by 'source-maps' in production mode
        devtool: "inline-source-map",

        output: {
            filename: 'js/' + (isProduction ? 'min/' : '') + '[name].js',
            path: path.resolve(__dirname, '../../upload-field-with-uppy-for-acf/asset'),
            publicPath: '/'
        },

        resolve: {
            modules: ["node_modules"]
        },

        externals: {
            jquery: 'jQuery'
        },

        plugins: [

            new CleanWebpackPlugin({
                dry: isProduction,
            }),

            new MiniCssExtractPlugin({
                filename: 'css/' + (isProduction ? 'min/' : '') + '[name].css'
            }),

            // provide jQuery and Popper.js dependencies
            new webpack.ProvidePlugin({
                $: 'jquery',
                jQuery: 'jquery',
                jquery: 'jquery',
                'window.jQuery': 'jquery',
                Popper: ['popper.js', 'default']
            }),

            //https://github.com/webpack-contrib/copy-webpack-plugin/issues/502
            new CopyWebpackPlugin({
                patterns: [
                    {
                        context: path.resolve(__dirname, 'node_modules') + '/@uppy/locales/dist',
                        from: '*',
                        to: 'js/locales/@uppy'
                    },
                ],
            }),

            new webpack.IgnorePlugin({
                resourceRegExp: /^\.\/locale$/,
                contextRegExp: /moment$/
            }),
        ],

        optimization: {
            //https://github.com/webpack/webpack.js.org/blob/ac5f6b4da11d6745005526f7c37b0d2bf629682a/src/content/configuration/optimization.md#optimizationruntimechunk
            runtimeChunk: {
                name: entrypoint => `runtime-${package.name}`
            },

            splitChunks: {
                chunks: 'all',
                maxInitialRequests: Infinity,
                minSize: 0,
                cacheGroups: {
                    vendor: {
                        test: /[\\/]node_modules[\\/]/,
                        name(module) {
                            // get the name. E.g. node_modules/packageName/not/this/part.js
                            // or node_modules/packageName
                            const packageName = module.context.match(/[\\/]node_modules[\\/](.*?)([\\/]|$)/)[1]

                            // npm package names are URL-safe, but some servers don't like @ symbols
                            return `npm/${packageName.replace('@', '')}`
                        },
                    },
                },
            },

            minimizer: [
                // CSS optimizer
                new OptimizeCSSAssetsWebpackPlugin(),
                // JS optimizer by default
                new TerserWebpackPlugin(),
            ],
        },

        module: {

            rules: [
                {
                    test: /\.(js|jsx)$/,
                    exclude: /node_modules/,
                    use: ["babel-loader"],
                },

                {
                    test: /\.(sa|sc|c)ss$/,
                    use: [
                        // The order in which webpack apply loaders is from last to first
                        MiniCssExtractPlugin.loader,
                        // Creates `style` nodes from JS strings
                        //'style-loader',
                        // Translates CSS into CommonJS
                        {
                          loader: 'css-loader',
                          options: {
                            sourceMap: !isProduction,
                          },
                        },
                        // Compiles Sass to CSS
                        {
                          loader: 'sass-loader',
                          options: {
                            //https://github.com/bholloway/resolve-url-loader/issues/212#issuecomment-1011630220
                            // resolve-url-loader: error processing CSS a valid source-map is not present (ensure preceding loaders output a source-map)
                            sourceMap: true,
                          },
                        },
                    ],
                },
            ]
        }
    }

    return config
}
