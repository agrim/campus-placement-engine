import type { Dispatch } from "react";
import { nextGuidedStep } from "./selectors";
import { roleConfig, roleOrder } from "./scenario";
import type { DemoAction, DemoSession, RoleId } from "./types";
import { Icon, type IconName } from "./Icon";

const roleIcon: Record<RoleId, IconName> = {
  control: "monitor",
  mobile: "phone",
  floor: "users",
  company: "building",
  placement: "briefcase",
  auditor: "shield",
};

type Props = {
  session: DemoSession;
  dispatch: Dispatch<DemoAction>;
};

export function RoleSwitcher({ session, dispatch }: Props) {
  const next = nextGuidedStep(session);

  return (
    <nav className="role-switcher" aria-label="Demo user profiles">
      {roleOrder.map((role) => {
        const active = session.activeRole === role;
        const waiting = next?.role === role;
        const label =
          role === "company"
            ? `${session.world.companies[session.companyScope].name} tracker`
            : roleConfig[role].label;
        return (
          <button
            key={role}
            type="button"
            className={`role-button ${active ? "is-active" : ""} ${waiting ? "has-action" : ""}`}
            aria-pressed={active}
            onClick={() => dispatch({ type: "switch-role", role })}
          >
            <span className="role-icon"><Icon name={roleIcon[role]} size={24} /></span>
            <span>{label}</span>
            {waiting ? <small>Action waiting</small> : null}
          </button>
        );
      })}
    </nav>
  );
}
