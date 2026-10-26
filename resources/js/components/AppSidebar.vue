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
import { index as purchaseOrdersIndex } from '@/routes/purchase-orders';
import { index as salesOrdersIndex } from '@/routes/sales-orders';
import { index as suppliersIndex } from '@/routes/suppliers';
import type { NavItem } from '@/types';

const page = usePage();

const modules = [
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
        title: 'Sales',
        href: page.props.currentTeam
            ? salesOrdersIndex(page.props.currentTeam.slug).url
            : '/',
        icon: ReceiptText,
    },
]);

const purchasingNavItems = computed<NavItem[]>(() => [
    {
        title: 'Purchase orders',
        href: page.props.currentTeam
            ? purchaseOrdersIndex(page.props.currentTeam.slug).url
            : purchaseOrdersIndex().url,
        icon: ShoppingCart,
    },
    {
        title: 'Suppliers',
        href: page.props.currentTeam
            ? suppliersIndex(page.props.currentTeam.slug).url
            : suppliersIndex().url,
        icon: Truck,
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
            <NavMain
                :items="mainNavItems"
                :modules="modules"
                :purchasing-items="purchasingNavItems"
            />
        </SidebarContent>

        <SidebarFooter>
            <NavUser />
        </SidebarFooter>
    </Sidebar>
    <slot />
</template>
