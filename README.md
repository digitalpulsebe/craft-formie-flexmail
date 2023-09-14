# Flexmail for Formie

Flexmail integration for Formie

## Requirements

This plugin requires Craft CMS 4.3.5 or later, and PHP 8.0.2 or later.
It also requires [Formie](https://github.com/verbb/formie) 2.0 or later, 
this plugin adds a custom integration for Formie.

You also need an account at [Flexmail](https://app.flexmail.eu/auth)

## Installation

To install the plugin, follow these instructions.

1. Open your terminal and go to your Craft project:

        cd /path/to/project

2. Then tell Composer to load the plugin:

        composer require digitalpulsebe/craft-formie-flexmail

3. Install the plugin.

        php craft plugin/install formie-flexmail

## Add an integration in the Formie settings

1. Open the Formie settings in a local environment
2. Go to 'Email marketing' under 'Integrations'
3. Click the 'New integration' button.
4. Fill in all the fields and follow instructions to create an API token
5. Click the 'refresh' button to check for a successful API connection

## Send form submissions to Flexmail

1. Open the 'Forms' overview in Formie
2. Select the form you need
3. In the tab integrations select 'Flexmail'
4. Click the refresh icon next to the 'List' dropdown.
5. Select a list (a.k.a. source)
6. Map each field carefully, be sure to configure your form fields to match the accepted content types:
   - Dropdown fields must have options available in Flexmail, you have to manually check them
   - Date fields don't support time




