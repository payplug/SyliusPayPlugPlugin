# Development

## Assets

For the sake of quickness, the usage of a tool like [Parcel](https://github.com/parcel-bundler/parcel/tree/1.x) shows that its efficiency is indeed undeniable.
So, if you want to edit assets (js, scss, ...) you'll likely go into `assets` and run `yarn install`.
Then, you'll find a list of commands inside `package.json` which are :

```bash
$ (cd assets && yarn build)
``` 

Or, if you prefer the dev mode; a `watch` command that compile in real time, then run:

```bash
$ (cd src/Resources/dev && yarn dev)
``` 

You can add any resources as far as Parcel can go,
but those have to be located in `/pages` otherwise they won't be compiled.

Assets can be found in `public/assets/oney` so you'll have to install them in your application by running:

```bash
$ bin/console assets:install --symlink
# or
$ bin/console sylius:theme:assets:install --symlink # e.g if bootstrapTheme is enabled 
``` 

To make it fully compatible with [Sylius Bootstrap Theme](https://github.com/Sylius/BootstrapTheme),
some lines have to be added to the main entrypoint (such as `app.js`) of the theme:

```js
const $ = require('jquery');
global.$ = global.jQuery = $;
```
