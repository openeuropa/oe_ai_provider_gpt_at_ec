# OpenEuropa GPT@EC AI Provider

Enables the use of GPT@EC as provider for the Drupal AI module.

:warning: This is a proof of concept.

## Installation

Install as you would normally install a contributed Drupal module. For further
information, see
[Installing Drupal Modules](https://www.drupal.org/docs/extending-drupal/installing-drupal-modules).

## Development

Inside the cloned project run:

```sh
# Fire up the Docker containers.
ddev start
# Install the PHP dependencies.
ddev poser
# Symlink the module inside "web/modules/custom".
ddev symlink-project
# Install Drupal and enable the module.
ddev install
# Run this if you want to use the eslint command.
ddev exec "cd web/core && yarn install"
```
