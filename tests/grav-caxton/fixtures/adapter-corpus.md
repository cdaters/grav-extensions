---
title: Caxton 0.1.1 adapter corpus
description: "Exact source remains authoritative."
---

# ATX heading

Setext heading
==============

Plain paragraph with **strong and *nested emphasis***, `inline code`, [a link](https://example.com/path?q=1), and <https://example.org>.

- unordered one
  - nested unordered
- [x] completed task
- [ ] incomplete task

3. ordered starts at three
4. ordered continues

> Blockquote with **formatting**.
>
> Second quoted paragraph.

---

~~~~php
$markdown = "**not emphasis**";
$twig = "{{ not_evaluated }}";
$shortcode = "[notice]not parsed[/notice]";
~~~~

![Safe media](images/example.jpg "Local media")

| Table | Remains |
| :--- | ---: |
| exact | source |

<section onclick="alert('never')">
  <script>window.__caxtonExecuted = true;</script>
</section>

{{ config.system.pages.theme }}

{% if system.callDangerousThing() %}
Never execute this.
{% endif %}

[notice class="warning"]
Nested [button url="https://example.com"]action[/button] remains exact.
[/notice]

Markdown before <span onmouseover="alert(1)">mixed HTML</span> after.

::: unknown-extension
Keep unusual indentation
    and trailing whitespace.
:::

Unicode: café naïve Ελληνικά 日本語 😀 é.

Editable before opaque.

{% opaque_boundary %}

Editable after opaque.
