export type RoleId =
  | "control"
  | "mobile"
  | "floor"
  | "company"
  | "placement"
  | "auditor";

export type CompanyId = "atlas" | "nova" | "river";

export type ApplicationStatus =
  | "idle"
  | "scheduled"
  | "intransit"
  | "arrived"
  | "requested"
  | "sendin"
  | "inside"
  | "exit"
  | "requestaway"
  | "sendaway"
  | "sent"
  | "placed";

export type DemoMode = "guided" | "free";

export type Candidate = {
  id: string;
  initials: string;
  name: string;
  program: string;
  location: string;
  privateTag: string;
  accommodation: string;
};

export type Company = {
  id: CompanyId;
  code: string;
  name: string;
};

export type Application = {
  id: string;
  candidateId: string;
  companyId: CompanyId;
  status: ApplicationStatus;
  time: string;
  originalTime: string;
  alternateTime?: string;
  round: string;
  room: string;
};

export type AuditEvent = {
  id: string;
  minute: number;
  actor: string;
  message: string;
  system?: boolean;
  companyId?: CompanyId;
};

export type DemoWorld = {
  candidates: Record<string, Candidate>;
  companies: Record<CompanyId, Company>;
  applications: Record<string, Application>;
  audit: AuditEvent[];
  clockMinute: number;
  clashResolved: boolean;
  interviewsCompleted: number;
  preferenceResolved: boolean;
  placementCompanyId: CompanyId | null;
  traceReviewed: boolean;
};

export type DemoSession = {
  world: DemoWorld;
  activeRole: RoleId;
  companyScope: CompanyId;
  selectedCandidateId: string;
  mode: DemoMode;
  completedStepCount: number;
  announcement: string;
  activityExpanded: boolean;
};

export type GuidedStepId =
  | "resolve-clash"
  | "start-transit-atlas"
  | "arrive-atlas"
  | "request-atlas"
  | "approve-atlas"
  | "run-atlas-interview"
  | "release-atlas"
  | "handover-atlas"
  | "arrive-nova"
  | "request-nova"
  | "approve-nova"
  | "run-nova-interview"
  | "release-nova"
  | "confirm-nova"
  | "record-placement"
  | "review-trace";

export type GuidedStep = {
  id: GuidedStepId;
  role: RoleId;
  companyScope?: CompanyId;
  act: number;
  actionLabel: string;
  title: string;
  instruction: string;
  benefit: string;
  completion: string;
};

export type DemoAction =
  | { type: "switch-role"; role: RoleId; companyScope?: CompanyId }
  | { type: "set-company-scope"; companyScope: CompanyId }
  | { type: "set-mode"; mode: DemoMode }
  | { type: "run-current-step" }
  | { type: "select-candidate"; candidateId: string }
  | { type: "toggle-activity" }
  | { type: "reset" };
