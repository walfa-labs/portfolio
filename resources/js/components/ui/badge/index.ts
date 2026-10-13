import type { VariantProps } from "class-variance-authority"
import { cva } from "class-variance-authority"

export { default as Badge } from "./Badge.vue"

export const badgeVariants = cva(
  "inline-flex items-center justify-center rounded-sm border-2 border-ink px-2 py-0.5 font-mono text-label font-medium w-fit whitespace-nowrap shrink-0 uppercase tracking-widest [&>svg]:size-3 gap-1 [&>svg]:pointer-events-none outline-hidden transition overflow-hidden",
  {
    variants: {
      variant: {
        default: "bg-primary text-primary-foreground",
        secondary: "bg-secondary text-secondary-foreground",
        destructive: "bg-destructive text-destructive-foreground",
        outline: "bg-card text-ink",
      },
      tone: {
        neutral: "bg-tone-neutral text-ink",
        viridian: "bg-tone-viridian text-ink",
        sky: "bg-tone-sky text-ink",
        marigold: "bg-tone-marigold text-ink",
        violet: "bg-tone-violet text-ink",
        vermilion: "bg-tone-vermilion text-ink",
        rose: "bg-tone-rose text-ink",
        leaf: "bg-tone-leaf text-ink",
      },
    },
    defaultVariants: {
      variant: "default",
    },
  },
)
export type BadgeVariants = VariantProps<typeof badgeVariants>
export type Tone = NonNullable<VariantProps<typeof badgeVariants>["tone"]>
