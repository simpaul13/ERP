<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import {
    BadgeDollarSign,
    Barcode,
    Boxes,
    ChartNoAxesCombined,
    LayoutGrid,
    ReceiptText,
    ShoppingCart,
    Truck,
    Warehouse,
} from '@lucide/vue';
import { computed } from 'vue';
import AppLogo from '@/components/AppLogo.vue';
import NavMain from '@/components/NavMain.vue';
import NavUser from '@/components/NavUser.vue';
import TeamSwitcher from '@/components/TeamSwitcher.vue';
import {
    Sidebar,
    SidebarContent,
    SidebarFooter,
    SidebarHeader,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';
import { dashboard } from '@/routes';
import type { NavItem } from '@/types';

const page = usePage();

const modules = [
    { title: 'Purchasing', icon: ShoppingCart },
    { title: 'Sales', icon: ReceiptText },
    { title: 'Finance', icon: BadgeDollarSign },
    { title: 'Reports & Operations', icon: ChartNoAxesCombined },
];

const dashboardUrl = computed(() =>
    page.props.currentTeam ? dashboard(page.props.currentTeam.slug).url : '/',
);

const mainNavItems = computed<NavItem[]>(() => [
    {
        title: 'Dashboard',
        href: dashboardUrl.value,
        icon: LayoutGrid,
    },
    {
        title: 'Inventory',
        href: page.props.currentTeam
            ? `/${page.props.currentTeam.slug}/inventories`
            : '/inventories',
        icon: Boxes,
    },
    {
        title: 'Scan stock',
        href: page.props.currentTeam
            ? `/${page.props.currentTeam.slug}/inventory-scans`
            : '/inventory-scans',
        icon: Barcode,
    },
    {
        title: 'Warehouses',
        href: page.props.currentTeam
            ? `/${page.props.currentTeam.slug}/warehouses`
            : '/warehouses',
        icon: Warehouse,
    },
    {
        title: 'Suppliers',
        href: page.props.currentTeam
            ? `/${page.props.currentTeam.slug}/suppliers`
            : '/suppliers',
        icon: Truck,
    },
    {
        title: 'Purchase orders',
        href: page.props.currentTeam
            ? `/${page.props.currentTeam.slug}/purchase-orders`
            : '/purchase-orders',
        icon: ShoppingCart,
    },
]);
</script>

<template>
    <Sidebar collapsible="icon" variant="inset">
        <SidebarHeader>
            <SidebarMenu>
                <SidebarMenuItem>
                    <SidebarMenuButton size="lg" as-child>
                        <Link :href="dashboardUrl">
                            <AppLogo />
                        </Link>
                    </SidebarMenuButton>
                </SidebarMenuItem>
            </SidebarMenu>
            <SidebarMenu>
                <SidebarMenuItem>
                    <TeamSwitcher />
                </SidebarMenuItem>
            </SidebarMenu>
        </SidebarHeader>

        <SidebarContent>
            <NavMain :items="mainNavItems" :modules="modules" />
        </SidebarContent>

        <SidebarFooter>
            <NavUser />
        </SidebarFooter>
    </Sidebar>
    <slot />
</template>
