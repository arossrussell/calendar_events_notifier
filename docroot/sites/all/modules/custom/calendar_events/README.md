## Calendar Events module

---
### This repo contains a Drupal 7 module that pulls customer event information from the Customer calendar. This information is then posted as an internal comment in relevant Zendesk tickets. This module is part of the Swarm initiative being undertaken by Acquia Support.

#### As a new user, follow these steps to get started with this module:

1. Add a new Drupal 7 application to Acquia Cloud
  a. Go to https://insight.acquia.com/subscriptions/add.
  b. Recommended naming `calendarevents{your name}`.
  c. Use the standard Drupal installation.
2. Download and install DevDesktop
3. Add the application to DevDesktop
4. Navigate to the `sites/all/modules` folder.
5. Create and navigate into a new `custom` folder (e.g. `mkdir custom`.)
6. Run `git clone https://github.com/bjkropff/calendar_events.git`.
7. Run `drush cc` from DevDesktop.
8. Install the "Calendar Events" module.

*Note: After making any changes, be sure to run `drush cc` :)*

### This module relies on Google's PHP API Client Library.  Take these steps to effectively install the library:

1. Download the client at https://github.com/googleapis/google-api-php-client/releases.
2. Unzip the client.
3. Rename the client folder 'google-api-php-client'.
4. Within your site's docroot, create a directory named 'vendor'.
5. Place 'google-api-php-client' into 'vendor'.

For additional information about this library, check out:
* https://developers.google.com/api-client-library/php/start/get_started.

### This module also relies on a Google Service Account.  The current iteration of this module specifically uses the following Service Account:

* support-service-account@calendar-events-218305.iam.gserviceaccount.com

When a Service Account is created, a public/private key pair is generated and downloaded to your machine; it serves as the only copy of this key.  Ask Blake for the .json file which contains the keys (note: let's figure out a Google Drive location where this file can live, so that all members of the project can access it).  Once you have obtained it, place the .json file in the same `vendor` directory where you added the PHP API Client Library.

*Note: If you end up using a different Service Account, the email address for that account will need to be added to the `Share with specific people` list located within the Customer Calendar's settings.*

For additional information about working with Service Accounts, check out:
* https://developers.google.com/api-client-library/php/auth/service-accounts
* https://cloud.google.com/iam/docs/creating-managing-service-accounts.
