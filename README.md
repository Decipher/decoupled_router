# Decoupled Router

[![Pipeline](https://git.drupalcode.org/project/decoupled_router/badges/2.x/pipeline.svg)](https://git.drupalcode.org/project/decoupled_router/-/pipelines)
[![Test](https://github.com/Decipher/decoupled_router/actions/workflows/test.yml/badge.svg?branch=2.x)](https://github.com/Decipher/decoupled_router/actions/workflows/test.yml?query=branch%3A2.x)
[![Coverage](https://codecov.io/gh/Decipher/decoupled_router/branch/2.x/graph/badge.svg)](https://codecov.io/gh/Decipher/decoupled_router/branch/2.x)

Handles the awkwardness of routing in decoupled architectures. It exposes a
`/router/translate-path` endpoint that resolves a Drupal path (or path alias)
to the routing information a decoupled frontend needs: the matching JSON:API
resource, entity data, redirect chain, and whether the path is the site's
home path.

For a full description of the module, visit the
[project page](https://www.drupal.org/project/decoupled_router).

Submit bug reports and feature suggestions, or track changes in the
[issue queue](https://www.drupal.org/project/issues/decoupled_router).

## Table of contents

- Requirements
- Installation
- Configuration
- Features
- FAQ
- Maintainers

## Requirements

This module requires the following modules:

- [Path Alias](https://www.drupal.org/project/drupal) (Drupal core)

Optional:

- [Redirect](https://www.drupal.org/project/redirect) (>=1.12) — enables
  redirect resolution alongside path translation

## Installation

1. Download and install via Composer:

   ```bash
   composer require drupal/decoupled_router
   ```

1. Enable the module:

   ```bash
   drush en decoupled_router
   ```

## Configuration

This module has no admin form. Its one setting lives in
`decoupled_router.settings`:

- `absolute_resolved_urls` (boolean, default `true`) — whether the
  `resolved` URL in translate-path responses is absolute or relative

Change it via `drush config:set decoupled_router.settings
absolute_resolved_urls false`, or with the Config Manager UI if you export
config to the filesystem. A settings form is tracked in
[#3560110](https://www.drupal.org/project/decoupled_router/issues/3560110).

## Features

- `/router/translate-path?path=<path>` endpoint resolving a Drupal path to
  JSON:API resource info, entity data, and cacheability metadata
- Redirect resolution via the optional Redirect module integration
- Non-entity route support (e.g. Views-backed pages)
- Multilingual path/redirect resolution

## FAQ

**Q: Does this module provide a UI?**

**A:** No. It's a backend-only API module — usually consumed by a decoupled
frontend framework such as [Druxt](https://druxtjs.org), which depends on it
directly.

**Q: What authentication methods does the endpoint support?**

**A:** `basic_auth`, `cookie`, `oauth2`, and `jwt_auth`, gated by the
`access content` permission.

## Maintainers

- e0ipso - [e0ipso](https://www.drupal.org/u/e0ipso)
- Stuart Clark - [deciphered](https://www.drupal.org/u/deciphered)
- Matt Glaman - [mglaman](https://www.drupal.org/u/mglaman)
