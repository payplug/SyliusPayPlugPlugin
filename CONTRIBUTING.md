## Installation

From the plugin root directory, run the following commands:

```bash
$ make install -e SYLIUS_VERSION=XX SYMFONY_VERSION=YY PHP_VERSION=ZZ
```

Default values : XX=1.14.0 and YY=6.4 and ZZ=8.2

To be able to setup the plugin database, remember to configure you database credentials
in `install/Application/.env.local` and `install/Application/.env.test.local`.

To reset test environment:
```bash
$ make reset
```

## Usage

### Running code analyse and tests

- GrumPHP (see configuration [grumphp.yml](grumphp.yml).)

  GrumPHP is executed by the Git pre-commit hook, but you can launch it manually with :

  ```bash
  $ make grumphp
  ```

- PHPUnit

  ```bash
  $ make phpunit
  ```

- Lints (PHP syntax, YAML, Twig, Symfony container)

  ```bash
  $ composer lint
  ```

  Each check also runs on its own: `composer lint:php`, `composer lint:yaml`, `composer lint:twig`, `composer lint:container`.

- ESLint (from the plugin root, Node 20.19 or later)

  ```bash
  $ npm ci
  $ npm run lint
  ```

- JS tests

  ```bash
  $ (cd assets && npm test)
  ```

### Opening Sylius with your plugin

- Using `test` environment:

    ```bash
    $ (cd tests/Application && bin/console server:run -d public -e test)
    ```

- Using `dev` environment:

    ```bash
    $ (cd tests/Application && bin/console server:run -d public -e dev)
    ```
