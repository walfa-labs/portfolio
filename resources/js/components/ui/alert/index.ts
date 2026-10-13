import type { VariantProps } from "class-variance-authority"
import { cva } from "class-variance-authority"

export { default as Alert } from "./Alert.vue"
export { default as AlertDescription } from "./AlertDescription.vue"
export { default as AlertTitle } from "./AlertTitle.vue"

export const alertVariants = cva(
  "relative w-full rounded-md border-2 border-ink px-4 py-3 text-sm grid has-[>svg]:grid-cols-[calc(var(--spacing)*4)_1fr] grid-cols-[0_1fr] has-[>svg]:gap-x-3 gap-y-0.5 items-start [&>svg]:size-4 [&>svg]:translate-y-0.5 [&>svg]:text-current shadow-hard-sm",
  {
    variants: {
      variant: {
        default: "bg-card text-card-foreground [&>svg]:text-primary",
        destructive:
          "bg-card text-destructive [&>svg]:text-destructive *:data-[slot=alert-description]:text-destructive",
        success:
          "bg-card text-success [&>svg]:text-success *:data-[slot=alert-description]:text-success",
      },
    },
    defaultVariants: {
      variant: "default",
    },
  },
)

export type AlertVariants = VariantProps<typeof alertVariants>
