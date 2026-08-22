import { guidedSteps, roleConfig } from "./scenario";
import type {
  Application,
  ApplicationStatus,
  DemoSession,
  GuidedStep,
  RoleId,
} from "./types";

export const statusLabel: Record<ApplicationStatus, string> = {
  idle: "Opportunity closed",
  scheduled: "Scheduled",
  intransit: "In transit",
  arrived: "Arrived",
  requested: "Candidate requested",
  sendin: "Ready to interview",
  inside: "In interview",
  exit: "Interview complete",
  requestaway: "Next handover requested",
  sendaway: "Ready for handover",
  sent: "Handed over",
  placed: "Placed",
};

export const statusTone: Record<ApplicationStatus, string> = {
  idle: "muted",
  scheduled: "warning",
  intransit: "progress",
  arrived: "ready",
  requested: "attention",
  sendin: "ready",
  inside: "active",
  exit: "complete",
  requestaway: "attention",
  sendaway: "progress",
  sent: "complete",
  placed: "placed",
};

export function nextGuidedStep(session: DemoSession): GuidedStep | null {
  return guidedSteps[session.completedStepCount] ?? null;
}

export function roleMatchesStep(session: DemoSession, step: GuidedStep): boolean {
  if (session.activeRole !== step.role) return false;
  return step.role !== "company" || step.companyScope === session.companyScope;
}

export function nextRoleLabel(session: DemoSession, step: GuidedStep): string {
  if (step.role === "company" && step.companyScope) {
    return `${session.world.companies[step.companyScope].name} tracker`;
  }
  return roleConfig[step.role].label;
}

export function applicationsForCandidate(
  session: DemoSession,
  candidateId: string,
): Application[] {
  return Object.values(session.world.applications).filter((application) => {
    if (application.candidateId !== candidateId) return false;
    return isApplicationVisible(session, application);
  });
}

const visibleStatusesByRole: Partial<Record<RoleId, ApplicationStatus[]>> = {
  mobile: ["scheduled", "intransit", "sent"],
  floor: ["intransit", "arrived", "requested", "sendin", "inside", "exit"],
  placement: ["requested", "sendin", "requestaway", "sendaway", "sent", "placed"],
};

function isApplicationVisible(session: DemoSession, application: Application): boolean {
  if (session.activeRole === "company") {
    return application.companyId === session.companyScope;
  }
  const visibleStatuses = visibleStatusesByRole[session.activeRole];
  return visibleStatuses ? visibleStatuses.includes(application.status) : true;
}

export function visibleCandidateIds(session: DemoSession): string[] {
  const ids = new Set<string>();
  for (const application of Object.values(session.world.applications)) {
    if (!isApplicationVisible(session, application)) {
      continue;
    }
    ids.add(application.candidateId);
  }
  return Array.from(ids).sort((a, b) => {
    if (a === "dev") return -1;
    if (b === "dev") return 1;
    return session.world.candidates[a].name.localeCompare(session.world.candidates[b].name);
  });
}

export function primaryApplication(session: DemoSession, candidateId: string): Application {
  const applications = applicationsForCandidate(session, candidateId);
  const active = applications.find((application) =>
    !["idle", "sent", "placed"].includes(application.status),
  );
  return active ?? applications[applications.length - 1];
}

export function canSeePrivate(role: RoleId): boolean {
  return role === "control" || role === "placement" || role === "auditor";
}

export function canSeeAccommodation(role: RoleId): boolean {
  return role !== "company";
}

export type JourneyStage = {
  label: string;
  detail: string;
  start: number;
  end: number;
};

export const journeyStages: JourneyStage[] = [
  { label: "Protect both interviews", detail: "Resolve the Atlas / Nova clash", start: 0, end: 1 },
  { label: "Reach Atlas Systems", detail: "Travel and floor arrival", start: 1, end: 3 },
  { label: "Complete the Atlas interview", detail: "Request, approve, interview", start: 3, end: 6 },
  { label: "Handover to Nova Labs", detail: "Release, route, and arrive", start: 6, end: 9 },
  { label: "Complete the Nova interview", detail: "Request, approve, interview, return", start: 9, end: 14 },
  { label: "Record and verify placement", detail: "Candidate preference and audit", start: 14, end: 16 },
];

export function formatClock(minute: number): string {
  const hours = Math.floor(minute / 60) % 24;
  const minutes = minute % 60;
  return `${String(hours).padStart(2, "0")}:${String(minutes).padStart(2, "0")}`;
}
