import type { VariantProps } from "class-variance-authority"
import { cva } from "class-variance-authority"

export { default as Button } from "./Button.vue"

const LIFT =
  "shadow-hard-sm hover:-translate-x-0.5 hover:-translate-y-0.5 hover:shadow-hard active:translate-x-0 active:translate-y-0 active:shadow-none"

export const buttonVariants = cva(
  "inline-flex items-center justify-center gap-2 whitespace-nowrap rounded-sm border-2 text-sm font-semibold transition disabled:pointer-events-none disabled:opacity-50 disabled:shadow-none [&_svg]:pointer-events-none [&_svg:not([class*='size-'])]:size-4 shrink-0 [&_svg]:shrink-0 outline-hidden aria-invalid:border-destructive",
  {
    variants: {
      variant: {
        default: `border-ink bg-primary text-primary-foreground hover:bg-primary-hover ${LIFT}`,
        destructive: `border-ink bg-destructive text-destructive-foreground hover:bg-destructive-hover ${LIFT}`,
        outline: `border-ink bg-card text-ink hover:bg-secondary ${LIFT}`,
        secondary: `border-ink bg-secondary text-secondary-foreground hover:bg-muted ${LIFT}`,
        ghost: "border-transparent bg-transparent text-ink hover:border-ink hover:bg-secondary",
        link: "border-transparent bg-transparent text-primary underline decoration-2 underline-offset-4 hover:text-primary-hover",
      },
      size: {
        "default": "h-10 px-5 py-2 has-[>svg]:px-4",
        "sm": "h-9 gap-1.5 px-4 has-[>svg]:px-3",
        "lg": "h-12 rounded-md px-7 has-[>svg]:px-5",
        "icon": "size-10",
        "icon-sm": "size-9",
        "icon-lg": "size-12",
      },
    },
    defaultVariants: {
      variant: "default",
      size: "default",
    },
  },
)
export type ButtonVariants = VariantProps<typeof buttonVariants>
