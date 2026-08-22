import type { SVGProps } from "react";

export type IconName =
  | "arrow"
  | "briefcase"
  | "building"
  | "check"
  | "clock"
  | "external"
  | "location"
  | "lock"
  | "monitor"
  | "phone"
  | "reset"
  | "shield"
  | "swap"
  | "users"
  | "warning";

type Props = SVGProps<SVGSVGElement> & {
  name: IconName;
  size?: number;
};

export function Icon({ name, size = 20, ...props }: Props) {
  const common = {
    width: size,
    height: size,
    viewBox: "0 0 24 24",
    fill: "none",
    stroke: "currentColor",
    strokeWidth: 1.8,
    strokeLinecap: "round" as const,
    strokeLinejoin: "round" as const,
    "aria-hidden": true,
    focusable: false,
    ...props,
  };

  switch (name) {
    case "arrow":
      return <svg {...common}><path d="M5 12h13M14 7l5 5-5 5" /></svg>;
    case "briefcase":
      return <svg {...common}><rect x="3" y="7" width="18" height="13" rx="1" /><path d="M8 7V4h8v3M3 12h18M10 12v2h4v-2" /></svg>;
    case "building":
      return <svg {...common}><path d="M4 21V7l8-4v18M12 9h8v12M2 21h20" /><path d="M7 9h2M7 13h2M7 17h2M15 12h2M15 16h2" /></svg>;
    case "check":
      return <svg {...common}><path d="m5 12 4 4L19 6" /></svg>;
    case "clock":
      return <svg {...common}><circle cx="12" cy="12" r="9" /><path d="M12 7v5l3 2" /></svg>;
    case "external":
      return <svg {...common}><path d="M14 4h6v6M20 4l-9 9" /><path d="M18 13v6a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1V7a1 1 0 0 1 1-1h6" /></svg>;
    case "location":
      return <svg {...common}><path d="M20 10c0 5-8 11-8 11S4 15 4 10a8 8 0 1 1 16 0Z" /><circle cx="12" cy="10" r="2.5" /></svg>;
    case "lock":
      return <svg {...common}><rect x="5" y="10" width="14" height="11" rx="2" /><path d="M8 10V7a4 4 0 0 1 8 0v3" /></svg>;
    case "monitor":
      return <svg {...common}><rect x="3" y="4" width="18" height="13" rx="1" /><path d="M8 21h8M12 17v4" /></svg>;
    case "phone":
      return <svg {...common}><rect x="7" y="2" width="10" height="20" rx="2" /><path d="M11 18h2" /></svg>;
    case "reset":
      return <svg {...common}><path d="M20 11a8 8 0 1 0-2.34 5.66M20 5v6h-6" /></svg>;
    case "shield":
      return <svg {...common}><path d="M12 22s8-3.5 8-10V5l-8-3-8 3v7c0 6.5 8 10 8 10Z" /><path d="m9 12 2 2 4-5" /></svg>;
    case "swap":
      return <svg {...common}><path d="M7 7h11l-3-3M17 17H6l3 3" /></svg>;
    case "users":
      return <svg {...common}><circle cx="9" cy="8" r="3" /><path d="M3 20v-2a6 6 0 0 1 12 0v2M16 5a3 3 0 0 1 0 6M18 14a5 5 0 0 1 3 4.6V20" /></svg>;
    case "warning":
      return <svg {...common}><path d="M12 3 2.5 20h19L12 3Z" /><path d="M12 9v4M12 17h.01" /></svg>;
  }
}
