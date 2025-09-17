# OpenEuropa GPT@EC AI Provider

Enables the use of GPT@EC as provider for the Drupal AI module.

:warning: This is a proof of concept.

## Installation

Add this repository to your composer.json:
```
repositories: [
  {
    "type": "git",
    "url": "https://github.com/brummbar/poc_oe_ai_provider_gpt_at_ec"
  },
  {
    "type": "git",
    "url": "https://github.com/openeuropa/gpt-at-ec-php-client"
  },
  ...
]
```

then execute:
```shell
composer require openeuropa/oe_ai_provider_gpt_at_ec
```

Install and enable as you would normally install a contributed Drupal module. For
further information, see [Installing Drupal Modules](https://www.drupal.org/docs/extending-drupal/installing-drupal-modules).

## Configuration

In order to use the provider, you need a GPT@EC key.\
Once you have obtained one, the recommended approach is to set the value as environmental
variable, e.g. `KEY_AI_GPT_AT_EC`.

Log in as a user with administrative rights, and create a new key in Drupal by
visiting `/admin/config/system/keys/add`:
* _Key name_: any name easily identifiable.
* _Key type_: set to `Authentication`.
* _Key provider_: select `Environment`.
* _Environment variable_: enter the variable name, e.g. as above `KEY_AI_GPT_AT_EC`.

Save the new key. Now visit `/admin/config/ai/providers/gpt-at-ec`. Select the key
previously created and save.\
If the key was valid, you should see a success message and a list of models, for
which you can check the quota consumption.

Now you can use GPT@EC as provider for any AI module functionality.

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
