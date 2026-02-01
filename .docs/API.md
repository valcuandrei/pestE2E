# API — Public Surface (v1)

This document defines the public API.
Anything here is considered stable once implemented.

---

## Entry point

- `e2e()`
- `e2e('project')`

---

## Project definition

```php
e2e()->project('frontend', fn ($p) => $p
    ->dir('js')
    ->runner('playwright')
    ->command('node node_modules/.bin/playwright test')
    ->report('junit', 'storage/e2e/frontend/junit.xml')
    ->env([
        'APP_URL' => 'http://localhost',
    ])
    ->params([
        'baseUrl' => 'http://localhost',
    ])
);
```
