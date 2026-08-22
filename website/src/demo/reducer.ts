import { createInitialSession, guidedSteps, roleConfig } from "./scenario";
import type {
  Application,
  ApplicationStatus,
  AuditEvent,
  DemoAction,
  DemoSession,
  DemoWorld,
  CompanyId,
  GuidedStepId,
} from "./types";

function updateApplication(
  world: DemoWorld,
  id: string,
  update: Partial<Application>,
): DemoWorld {
  return {
    ...world,
    applications: {
      ...world.applications,
      [id]: { ...world.applications[id], ...update },
    },
  };
}

function setStatus(world: DemoWorld, id: string, status: ApplicationStatus): DemoWorld {
  return updateApplication(world, id, { status });
}

function setDevLocation(world: DemoWorld, location: string): DemoWorld {
  return {
    ...world,
    candidates: {
      ...world.candidates,
      dev: { ...world.candidates.dev, location },
    },
  };
}

function addEvent(
  world: DemoWorld,
  actor: string,
  message: string,
  minutes: number,
  system = false,
  companyId?: CompanyId,
): DemoWorld {
  const nextMinute = world.clockMinute + minutes;
  const event: AuditEvent = {
    id: `E${world.audit.length + 1}`,
    minute: nextMinute,
    actor,
    message,
    system,
    companyId,
  };
  return {
    ...world,
    clockMinute: nextMinute,
    audit: [event, ...world.audit],
  };
}

function applyDomainStep(world: DemoWorld, stepId: GuidedStepId): DemoWorld {
  let next = world;

  switch (stepId) {
    case "resolve-clash":
      next = updateApplication(next, "dev-nova", { time: "11:50" });
      next = { ...next, clashResolved: true };
      return addEvent(
        next,
        "Control room",
        "Moved Dev’s Nova Labs interview from 11:20 to the safe 11:50 slot.",
        1,
      );
    case "start-transit-atlas":
      next = setStatus(next, "dev-atlas", "intransit");
      next = setDevLocation(next, "North corridor · en route to Atlas Systems");
      return addEvent(next, "Mobile tracker", "Dev left the academic block for Atlas Systems.", 12, false, "atlas");
    case "arrive-atlas":
      next = setStatus(next, "dev-atlas", "arrived");
      next = setDevLocation(next, "Atlas reception · Room A1");
      return addEvent(next, "Floor coordinator", "Dev arrived at Atlas Systems, Room A1.", 7, false, "atlas");
    case "request-atlas":
      next = setStatus(next, "dev-atlas", "requested");
      return addEvent(next, "Atlas tracker", "Atlas Systems requested Dev for interview.", 1, false, "atlas");
    case "approve-atlas":
      next = setStatus(next, "dev-atlas", "sendin");
      return addEvent(next, "Placement office", "Approved Dev for the Atlas Systems interview.", 2, false, "atlas");
    case "run-atlas-interview":
      next = setStatus(next, "dev-atlas", "inside");
      next = setDevLocation(next, "Atlas interview room · Room A1");
      next = addEvent(next, "Atlas tracker", "Started Dev’s Atlas Systems interview.", 7, false, "atlas");
      next = setStatus(next, "dev-atlas", "exit");
      next = addEvent(next, "Atlas tracker", "Completed Dev’s Atlas Systems interview.", 20, false, "atlas");
      next = setStatus(next, "dev-atlas", "requestaway");
      next = { ...next, interviewsCompleted: 1 };
      return addEvent(next, "Atlas tracker", "Requested Dev’s handover to the next interview.", 1, false, "atlas");
    case "release-atlas":
      next = setStatus(next, "dev-atlas", "sendaway");
      return addEvent(next, "Placement office", "Released Dev from Atlas Systems for handover.", 1, false, "atlas");
    case "handover-atlas":
      next = setStatus(next, "dev-atlas", "sent");
      next = setStatus(next, "dev-nova", "intransit");
      next = setDevLocation(next, "East corridor · en route to Nova Labs");
      next = addEvent(next, "Atlas tracker", "Confirmed Dev’s handover from Atlas Systems.", 2, false, "atlas");
      return addEvent(
        next,
        "System",
        "Started Dev’s Nova Labs application in transit and recorded the Atlas → Nova route.",
        1,
        true,
      );
    case "arrive-nova":
      next = setStatus(next, "dev-nova", "arrived");
      next = setDevLocation(next, "Nova reception · Room B2");
      return addEvent(next, "Floor coordinator", "Dev arrived at Nova Labs, Room B2.", 8, false, "nova");
    case "request-nova":
      next = setStatus(next, "dev-nova", "requested");
      return addEvent(next, "Nova tracker", "Nova Labs requested Dev for interview.", 1, false, "nova");
    case "approve-nova":
      next = setStatus(next, "dev-nova", "sendin");
      return addEvent(next, "Placement office", "Approved Dev for the Nova Labs interview.", 1, false, "nova");
    case "run-nova-interview":
      next = setStatus(next, "dev-nova", "inside");
      next = setDevLocation(next, "Nova interview room · Room B2");
      next = addEvent(next, "Nova tracker", "Started Dev’s Nova Labs interview.", 1, false, "nova");
      next = setStatus(next, "dev-nova", "exit");
      next = addEvent(next, "Nova tracker", "Completed Dev’s Nova Labs interview.", 20, false, "nova");
      next = setStatus(next, "dev-nova", "requestaway");
      next = { ...next, interviewsCompleted: 2 };
      return addEvent(next, "Nova tracker", "Requested Dev’s return to the placement office.", 1, false, "nova");
    case "release-nova":
      next = setStatus(next, "dev-nova", "sendaway");
      return addEvent(next, "Placement office", "Released Dev from the Nova Labs process.", 1, false, "nova");
    case "confirm-nova":
      next = setStatus(next, "dev-nova", "sent");
      next = setDevLocation(next, "Placement office");
      return addEvent(next, "Nova tracker", "Confirmed Dev’s final handover to placement.", 1, false, "nova");
    case "record-placement":
      next = setStatus(next, "dev-nova", "placed");
      next = setStatus(next, "dev-atlas", "idle");
      next = {
        ...next,
        placementCompanyId: "nova",
        preferenceResolved: true,
      };
      next = addEvent(
        next,
        "Placement office",
        "Recorded Dev’s preference for Nova Labs and completed his placement.",
        2,
      );
      return addEvent(
        next,
        "System",
        "Closed Dev’s competing Atlas application after the placement decision.",
        0,
        true,
      );
    case "review-trace":
      return { ...next, traceReviewed: true };
  }
}

export function demoReducer(state: DemoSession, action: DemoAction): DemoSession {
  switch (action.type) {
    case "switch-role":
      return {
        ...state,
        activeRole: action.role,
        companyScope: action.companyScope ?? state.companyScope,
        announcement: `${roleConfig[action.role].label} view active. ${roleConfig[action.role].summary}`,
      };
    case "set-company-scope":
      return {
        ...state,
        companyScope: action.companyScope,
        announcement: `${state.world.companies[action.companyScope].name} company scope active.`,
      };
    case "set-mode":
      return {
        ...state,
        mode: action.mode,
        announcement:
          action.mode === "guided"
            ? "Guided demo active. Follow the recommended role and action."
            : "Profile exploration active. Switch roles while the shared scenario keeps its state.",
      };
    case "select-candidate":
      return {
        ...state,
        selectedCandidateId: action.candidateId,
        announcement: `${state.world.candidates[action.candidateId].name} selected.`,
      };
    case "toggle-activity":
      return { ...state, activityExpanded: !state.activityExpanded };
    case "reset":
      return createInitialSession();
    case "run-current-step": {
      const step = guidedSteps[state.completedStepCount];
      if (!step) {
        return { ...state, announcement: "The placement mission is already complete." };
      }
      if (state.selectedCandidateId !== "dev") {
        return {
          ...state,
          announcement: "This guided mission follows Dev Malhotra. Select Dev to continue.",
        };
      }
      const wrongRole = step.role !== state.activeRole;
      const wrongScope =
        step.role === "company" && step.companyScope !== state.companyScope;
      if (wrongRole || wrongScope) {
        const roleLabel =
          step.role === "company" && step.companyScope
            ? `${state.world.companies[step.companyScope].name} tracker`
            : roleConfig[step.role].label;
        return {
          ...state,
          announcement: `${roleLabel} must complete the next action. Switch profiles to continue.`,
        };
      }
      const nextWorld = applyDomainStep(state.world, step.id);
      return {
        ...state,
        world: nextWorld,
        completedStepCount: state.completedStepCount + 1,
        announcement: step.completion,
      };
    }
  }
}
