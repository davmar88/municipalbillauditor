import AppLogoIcon from '@/components/app-logo-icon';

export default function AppLogo() {
    return (
        <>
            <AppLogoIcon className="size-7 shrink-0 text-sidebar-primary" />
            <div className="ml-1 flex flex-1 flex-col leading-none">
                <span className="font-mono text-[10px] tracking-[0.12em] text-sidebar-foreground/80">
                    A FARM OF RIVAL FACTIONS
                </span>
                <span className="mt-0.5 truncate text-lg font-bold tracking-tight">
                    Revenue<span className="text-sidebar-primary">Guard</span>
                </span>
            </div>
        </>
    );
}
