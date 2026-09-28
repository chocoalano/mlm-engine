<script setup lang="ts">
/**
 * No chrome at all — the layout a panel's auth pages declare.
 *
 * They already draw their own frame with `PanelAuthLayout`, so what they need
 * from a layout is the opposite of a layout: a way to say "not the
 * application's". Without it they inherit whatever the host's resolver
 * applies to a page that names none, which on a starter kit is `AppLayout` —
 * the signed-in application shell, wrapped around a login screen.
 *
 * `layout: null` would be the obvious way to say this and does not work: the
 * common resolver is `page.default.layout = page.default.layout || AppLayout`,
 * and `null || AppLayout` is `AppLayout`. A component that renders nothing but
 * its slot survives that expression.
 */
</script>

<template>
    <slot />
</template>
