import { ChevronDownIcon } from "lucide-react"
import * as React from "react"

import { cn } from "@/lib/utils"

/**
 * A plain <select>, styled like the inputs. It posts with the form and
 * uses the phone's own picker.
 */
function NativeSelect({ className, children, ...props }: React.ComponentProps<"select">) {
  return (
    <div className="relative w-full">
      <select
        data-slot="native-select"
        className={cn(
          "border-input focus-visible:border-ring focus-visible:ring-ring/50 aria-invalid:ring-destructive/20 dark:aria-invalid:ring-destructive/40 aria-invalid:border-destructive h-11 w-full min-w-0 appearance-none rounded-md border bg-transparent py-1 pr-9 pl-3 text-base shadow-xs transition-[color,box-shadow] outline-none focus-visible:ring-[3px] disabled:cursor-not-allowed disabled:opacity-50 md:text-sm dark:bg-input/30 [&>option]:bg-popover [&>option]:text-popover-foreground",
          className
        )}
        {...props}
      >
        {children}
      </select>
      <ChevronDownIcon
        aria-hidden
        className="pointer-events-none absolute top-1/2 right-3 size-4 -translate-y-1/2 opacity-50"
      />
    </div>
  )
}

export { NativeSelect }
