<?php
// Gmail read-only access for the USPTO-notice intake (lib/gmail_pull.php). Copy to gmail/config.php (mode 600) and point
// config.php 'gmail_config' at it. Create an OAuth client (type "Desktop app") in Google Cloud, enable the Gmail API, and obtain
// a refresh token for the scope https://www.googleapis.com/auth/gmail.readonly for the mailbox that receives USPTO e-mail.
// Read-only: nothing is marked read, moved or sent.
return [
  'client_id'     => '',
  'client_secret' => '',
  'refresh_token' => '',
  'token_cache'   => __DIR__ . '/token-cache.json',
  'max_threads'   => 100,
];
