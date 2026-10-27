# OpenEuropa GPT@EC AI Provider

Enables the use of GPT@EC as provider for the Drupal AI module.

## Installation

Add this repository to your composer.json:
```
repositories: [
  {
    "type": "git",
    "url": "https://github.com/openeuropa/oe_ai_provider_gpt_at_ec"
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

## Chat endpoint

Chat operations with the GPT models (`gpt-*`) are sent to the GPT@EC Responses
endpoint (`/responses`). Previous versions used the Chat Completions endpoint
(`/chat/completions`) for all models.

GPT@EC does not serve the other models, such as the Mistral ones, on the
Responses endpoint, so they still use the Chat Completions endpoint.

The switch brings the following changes:
* The `max_tokens` chat setting is renamed to `max_output_tokens`. Configuration
  saved with `max_tokens` keeps working, but it should be updated to the new
  name.
* Images and PDF documents can be attached to chat messages sent to GPT models.
  They are sent inline, as GPT@EC has no file upload endpoint. Attachments are
  not sent to the other models.

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
