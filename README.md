# neuro-ai.ru site patches: SEOline VK hashtags

## Summary

This proposal adds automatic VK hashtags for SEOline VK autoposting.

When SEOline posts a content item to VK, it now reads the item's `seo_keys` field, treats commas as keyword separators, converts each keyword phrase to a VK hashtag, and appends the hashtags to the post text.

Example:

```text
нейросети, искусственный интеллект, AI инструменты
```

becomes:

```text
#Нейросети #ИскусственныйИнтеллект #AIИнструменты
```

## Repository Layout

This repository stores only changed site files, preserving paths from the site web root.

For the `neuro-ai.ru` site, changed files are placed under:

```text
neuro-ai.ru/
```

That means this file:

```text
neuro-ai.ru/system/controllers/seoline/frontend.php
```

maps to this server path:

```text
system/controllers/seoline/frontend.php
```

## Changed File

```text
neuro-ai.ru/system/controllers/seoline/frontend.php
```

## Implementation Notes

- Added private method `buildSeoKeyHashtags($seo_keys)`.
- The method:
  - splits `seo_keys` by comma;
  - trims each keyword;
  - removes spaces inside keyword phrases by joining words in CamelCase;
  - keeps Cyrillic, Latin letters, digits, underscores, and hyphens;
  - removes duplicates;
  - returns hashtags separated by spaces.
- `postToVK()` appends generated hashtags after the "read full" link.

## Validation

Checked locally with:

```text
php -l frontend.php
```

on PHP 8.3 and PHP 7.2.
