---
title: Caxton fixture
published: false
---

# Source faithful editor

Plain paragraph with Unicode: café, naïve, Ελληνικά, 日本語.

- list item
  - nested item

> A quote that remains opaque.

```twig
{{ markdown_looking_value }}
[notice]not a shortcode here[/notice]
```

![A title](image with spaces.jpg "Media title")

| Name | Value |
| --- | --- |
| one | two |

{% if page.header.enabled %}
<strong>{{ page.title }}</strong>
{% endif %}

[notice class="blue"]
Whitespace and nesting stay exact.
[/notice]
