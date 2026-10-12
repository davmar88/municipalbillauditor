import {
    Building2,
    FileText,
    House,
    LayoutGrid,
    ReceiptText,
    Scale,
    Wallet,
} from 'lucide-react';
import { index as bills } from '@/routes/bills';
import { index as credit } from '@/routes/credit';
import { index as disputes } from '@/routes/disputes';
import { index as properties } from '@/routes/properties';
import { portfolio } from '@/routes';
import type { NavItem, OrganisationKind } from '@/types';

/**
 * Each kind of customer gets the screens in the order they use them:
 * households start at their bills, agents at the portfolio and businesses
 * at their claims.
 */
export function mainNavigation(kind: OrganisationKind | undefined): NavItem[] {
    switch (kind) {
        case 'agent':
            return [
                { title: 'Portfolio', href: portfolio(), icon: LayoutGrid },
                { title: 'Bills', href: bills(), icon: ReceiptText },
                { title: 'Disputes', href: disputes(), icon: FileText },
                { title: 'Properties', href: properties(), icon: Building2 },
                { title: 'Credit', href: credit(), icon: Wallet },
            ];
        case 'business':
            return [
                { title: 'Claims', href: disputes(), icon: Scale },
                { title: 'Bills', href: bills(), icon: ReceiptText },
                { title: 'Properties', href: properties(), icon: Building2 },
                { title: 'Credit', href: credit(), icon: Wallet },
            ];
        default:
            return [
                { title: 'Bills', href: bills(), icon: ReceiptText },
                { title: 'Disputes', href: disputes(), icon: FileText },
                { title: 'Property', href: properties(), icon: House },
                { title: 'Credit', href: credit(), icon: Wallet },
            ];
    }
}
