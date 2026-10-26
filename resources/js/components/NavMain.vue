<script setup lang="ts">
import { Link } from "@inertiajs/vue3";
import type { LucideIcon } from "@lucide/vue";
import {
    SidebarGroup,
    SidebarGroupLabel,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
} from "@/components/ui/sidebar";
import { useCurrentUrl } from "@/composables/useCurrentUrl";
import type { NavItem } from "@/types";

defineProps<{
    items: NavItem[];
    modules: { title: string; icon: LucideIcon }[];
    purchasingItems: NavItem[];
    financeItems: NavItem[];
}>();

const { isCurrentOrParentUrl, isCurrentUrl } = useCurrentUrl();
</script>

<template>
    <SidebarGroup class="px-2 py-0">
        <SidebarGroupLabel>Platform</SidebarGroupLabel>
        <SidebarMenu>
            <SidebarMenuItem v-for="item in items" :key="item.title">
                <SidebarMenuButton
                    as-child
                    :is-active="isCurrentUrl(item.href)"
                    :tooltip="item.title"
                >
                    <Link :href="item.href">
                        <component :is="item.icon" />
                        <span>{{ item.title }}</span>
                    </Link>
                </SidebarMenuButton>
            </SidebarMenuItem>
            <SidebarMenuItem v-for="module in modules" :key="module.title">
                <SidebarMenuButton as="div" class="pointer-events-none">
                    <component :is="module.icon" aria-hidden="true" />
                    <span>{{ module.title }}</span>
                </SidebarMenuButton>
            </SidebarMenuItem>
        </SidebarMenu>
    </SidebarGroup>

    <SidebarGroup class="px-2 py-0">
        <SidebarGroupLabel>Finance</SidebarGroupLabel>
        <SidebarMenu>
            <SidebarMenuItem v-for="item in financeItems" :key="item.title">
                <SidebarMenuButton
                    as-child
                    :is-active="isCurrentOrParentUrl(item.href)"
                    :tooltip="item.title"
                >
                    <Link :href="item.href"
                        ><component
                            :is="item.icon"
                            aria-hidden="true"
                        /><span>{{ item.title }}</span></Link
                    >
                </SidebarMenuButton>
            </SidebarMenuItem>
        </SidebarMenu>
    </SidebarGroup>

    <SidebarGroup class="px-2 py-0">
        <SidebarGroupLabel>Purchasing</SidebarGroupLabel>
        <SidebarMenu>
            <SidebarMenuItem v-for="item in purchasingItems" :key="item.title">
                <SidebarMenuButton
                    as-child
                    :is-active="isCurrentOrParentUrl(item.href)"
                    :tooltip="item.title"
                >
                    <Link
                        :href="item.href"
                        :aria-current="
                            isCurrentUrl(item.href) ? 'page' : undefined
                        "
                    >
                        <component :is="item.icon" aria-hidden="true" />
                        <span>{{ item.title }}</span>
                    </Link>
                </SidebarMenuButton>
            </SidebarMenuItem>
        </SidebarMenu>
    </SidebarGroup>
</template>
