<script setup>
import { CalendarClock, CalendarDays, ChartNoAxesColumn, Landmark, LayoutDashboard, LogOut, Palette, Percent, Receipt, Settings, Store, Tag, Truck } from '@lucide/vue';
import { ConfigProvider, NavigationMenuItem, NavigationMenuLink, NavigationMenuList, NavigationMenuRoot, TooltipProvider } from 'reka-ui';
import { onMounted, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import LanguageSelect from '../components/ui/LanguageSelect.vue';
import ToastHost from '../components/ui/ToastHost.vue';
import { useSession } from '../composables/useSession.js';
import { intlLocale } from '../i18n/locale.js';

defineProps({
    title: { type: String, required: true },
});

const { t } = useI18n();

const groups = [
    { label: null, links: [{ href: '/dashboard', label: 'layout.nav.dashboard', icon: LayoutDashboard }] },
    {
        label: 'layout.nav.sales',
        links: [
            { href: '/orders', label: 'layout.nav.orders', icon: Receipt },
            { href: '/events', label: 'layout.nav.events', icon: CalendarDays },
            { href: '/discounts', label: 'layout.nav.discounts', icon: Percent },
            { href: '/channels', label: 'layout.nav.channels', icon: Store },
        ],
    },
    {
        label: 'layout.nav.workshop',
        links: [
            { href: '/designs', label: 'layout.nav.designs', icon: Palette },
            { href: '/planning', label: 'layout.nav.planning', icon: CalendarClock },
            { href: '/products', label: 'layout.nav.products', icon: Tag },
            { href: '/supplier-orders', label: 'layout.nav.suppliers', icon: Truck },
        ],
    },
    {
        label: 'layout.nav.office',
        links: [
            { href: '/reports/products', label: 'layout.nav.productReports', icon: ChartNoAxesColumn },
            { href: '/accounting', label: 'layout.nav.accounting', icon: Landmark },
        ],
    },
    { label: null, bottom: true, links: [{ href: '/settings', label: 'layout.nav.settings', icon: Settings }] },
];

const session = useSession();

const currentPath = window.location.pathname;
const isActive = (href) => currentPath === href || currentPath.startsWith(`${href}/`);

const sidebar = ref(null);

function revealActiveLink() {
    const active = sidebar.value?.querySelector('.app-layout__link--active');
    const menu = active?.closest('.app-layout__menu');
    if (!menu || menu.scrollWidth <= menu.clientWidth) return;
    const link = active.getBoundingClientRect();
    menu.scrollLeft += link.left - menu.getBoundingClientRect().left - (menu.clientWidth - link.width) / 2;
}

onMounted(revealActiveLink);
</script>

<template>
    <ConfigProvider :locale="intlLocale()">
        <TooltipProvider :delay-duration="400">
            <div class="app-layout">
                <aside ref="sidebar" class="app-layout__sidebar">
                    <a class="app-layout__brand" href="/">mossytrunk</a>
                    <NavigationMenuRoot class="app-layout__menu" orientation="vertical" :aria-label="t('layout.nav.label')">
                        <NavigationMenuList class="app-layout__nav">
                            <NavigationMenuItem
                                v-for="(group, index) in groups"
                                :key="group.label ?? index"
                                :class="['app-layout__group', { 'app-layout__group--bottom': group.bottom }]"
                            >
                                <span v-if="group.label" :id="`nav-group-${index}`" class="app-layout__group-label">{{ t(group.label) }}</span>
                                <ul class="app-layout__group-links" :aria-labelledby="group.label ? `nav-group-${index}` : undefined">
                                    <li v-for="link in group.links" :key="link.href">
                                        <NavigationMenuLink
                                            :href="link.href"
                                            :active="isActive(link.href)"
                                            :class="['app-layout__link', { 'app-layout__link--active': isActive(link.href) }]"
                                        >
                                            <component :is="link.icon" class="app-layout__icon" size="1.125rem" :stroke-width="1.75" aria-hidden="true" />
                                            {{ t(link.label) }}
                                        </NavigationMenuLink>
                                    </li>
                                </ul>
                            </NavigationMenuItem>
                        </NavigationMenuList>
                    </NavigationMenuRoot>
                    <div v-if="session" class="app-layout__account">
                        <p class="app-layout__workspace" :title="session.workspace">{{ session.workspace }}</p>
                        <p class="app-layout__email" :title="session.email">{{ session.email }}</p>
                        <form method="post" action="/logout" data-turbo="false">
                            <input type="hidden" name="_csrf_token" :value="session.logoutToken">
                            <button type="submit" class="app-layout__logout">
                                <LogOut size="1rem" :stroke-width="1.75" aria-hidden="true" />
                                <span class="app-layout__logout-label">{{ t('layout.signOut') }}</span>
                            </button>
                        </form>
                        <LanguageSelect class="app-layout__language" />
                    </div>
                </aside>

                <main class="app-layout__main">
                    <header class="app-layout__heading">
                        <div class="app-layout__titles">
                            <div v-if="$slots.back" class="app-layout__back"><slot name="back" /></div>
                            <h1 class="app-layout__title">{{ title }}</h1>
                        </div>
                        <div class="app-layout__actions"><slot name="actions" /></div>
                    </header>
                    <slot />
                </main>

                <ToastHost />
            </div>
        </TooltipProvider>
    </ConfigProvider>
</template>

<style scoped>
.app-layout {
    display: grid;
    grid-template-columns: var(--sidebar-width) minmax(0, 1fr);
    align-content: start;
    min-height: 100vh;
}

.app-layout__sidebar {
    position: sticky;
    top: 0;
    height: 100vh;
    display: flex;
    flex-direction: column;
    gap: var(--space-6);
    padding: var(--space-6) var(--space-4);
    background: var(--color-sidebar);
    border-right: 0.0625rem solid var(--color-border);
}

.app-layout__brand {
    padding: 0 var(--space-3);
    font-family: var(--font-display);
    font-size: 1.6rem;
    line-height: 1;
    color: var(--color-ink);
    text-decoration: none;
}

.app-layout__menu { display: flex; flex: 1; flex-direction: column; min-height: 0; overflow-y: auto; }
.app-layout__menu :deep(> div) { display: flex; flex: 1; flex-direction: column; }
.app-layout__sidebar :deep(.app-layout__nav) { display: flex; flex: 1; flex-direction: column; gap: var(--space-4); margin: 0; padding: 0; list-style: none; }
.app-layout__group { display: flex; flex-direction: column; gap: var(--space-1); }
.app-layout__group--bottom { margin-top: auto; }
.app-layout__group-label { padding: 0 var(--space-3); color: var(--color-subtle); font-size: var(--font-size-xs); font-weight: 600; }
.app-layout__group-links { display: flex; flex-direction: column; gap: var(--space-1); margin: 0; padding: 0; list-style: none; }

.app-layout__link {
    display: flex;
    align-items: center;
    gap: var(--space-3);
    padding: var(--space-2) var(--space-3);
    border-left: 0.125rem solid transparent;
    font-weight: 500;
    color: var(--color-muted);
    text-decoration: none;
    white-space: nowrap;
    transition: color var(--transition), background var(--transition), border-color var(--transition);
}

.app-layout__link:hover { color: var(--color-ink); background: var(--color-bg); }

.app-layout__link--active {
    color: var(--color-ink);
    background: var(--color-accent-soft);
    border-left-color: var(--color-accent);
}

.app-layout__icon { flex-shrink: 0; color: var(--color-subtle); transition: color var(--transition); }
.app-layout__link:hover .app-layout__icon { color: var(--color-ink); }
.app-layout__link--active .app-layout__icon { color: var(--color-accent); }

.app-layout__account {
    padding: var(--space-4) var(--space-3) 0;
    border-top: 0.0625rem solid var(--color-border);
    font-size: var(--font-size-sm);
}

.app-layout__workspace,
.app-layout__email { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.app-layout__workspace { margin: 0; font-weight: 600; color: var(--color-ink); }
.app-layout__email { margin: 0 0 var(--space-2); color: var(--color-subtle); }

.app-layout__logout {
    display: inline-flex;
    align-items: center;
    gap: var(--space-2);
    padding: 0;
    border: none;
    background: none;
    color: var(--color-muted);
    cursor: pointer;
    transition: color var(--transition);
}

.app-layout__logout:hover { color: var(--color-ink); }
.app-layout__language { margin-top: var(--space-2); }

.app-layout__main {
    min-width: 0;
    overflow-x: clip;
    padding: var(--space-6) var(--space-7);
}

.app-layout__heading {
    display: flex;
    align-items: flex-end;
    justify-content: space-between;
    gap: var(--space-4);
    flex-wrap: wrap;
    margin-bottom: var(--space-5);
    padding-bottom: var(--space-4);
    border-bottom: 0.0625rem solid var(--color-border);
}

.app-layout__back { margin-bottom: var(--space-2); font-size: var(--font-size-md); }
.app-layout__back :deep(a) { color: var(--color-muted); text-decoration: none; }
.app-layout__back :deep(a:hover) { color: var(--color-ink); }
.app-layout__title { margin: 0; }
.app-layout__actions { display: flex; gap: var(--space-2); flex-wrap: wrap; }

@media (max-width: 53.75rem) {
    .app-layout { grid-template-columns: minmax(0, 1fr); }

    .app-layout__sidebar {
        position: static;
        height: auto;
        display: grid;
        grid-template-columns: minmax(0, 1fr) auto;
        align-items: center;
        gap: var(--space-2) var(--space-3);
        padding: var(--space-3) var(--space-4) 0;
        border-right: none;
        border-bottom: 0.0625rem solid var(--color-border);
    }

    .app-layout__menu { grid-column: 1 / -1; grid-row: 2; min-width: 0; overflow-x: auto; scrollbar-width: none; }
    .app-layout__sidebar :deep(.app-layout__nav) { flex-direction: row; flex-wrap: nowrap; gap: var(--space-1); }
    .app-layout__group,
    .app-layout__group-links { flex-direction: row; }
    .app-layout__group--bottom { margin-top: 0; }
    .app-layout__group-label { display: none; }
    .app-layout__link { border-left: none; border-bottom: 0.125rem solid transparent; padding: var(--space-2); white-space: nowrap; }
    .app-layout__link--active { border-bottom-color: var(--color-accent); }
    .app-layout__account { grid-column: 2; grid-row: 1; display: flex; align-items: center; gap: var(--space-3); margin-top: 0; padding: 0; border-top: none; }
    .app-layout__workspace,
    .app-layout__email { display: none; }
    .app-layout__main { padding: var(--space-4); }
}

@media (max-width: 30rem) {
    .app-layout__logout-label {
        position: absolute;
        width: 0.0625rem;
        height: 0.0625rem;
        overflow: hidden;
        clip-path: inset(50%);
        white-space: nowrap;
    }
}
</style>
