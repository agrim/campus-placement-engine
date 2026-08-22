import { describe, expect, it } from "vitest";
import { demoReducer } from "./reducer";
import { createInitialSession, guidedSteps } from "./scenario";
import type { DemoSession, GuidedStep } from "./types";

function activateStep(session: DemoSession, step: GuidedStep): DemoSession {
  return demoReducer(session, {
    type: "switch-role",
    role: step.role,
    companyScope: step.companyScope,
  });
}

describe("demoReducer", () => {
  it("rejects a workflow action from the wrong role without mutating the world", () => {
    const initial = createInitialSession();
    const worldBefore = initial.world;
    const mobile = demoReducer(initial, { type: "switch-role", role: "mobile" });
    const rejected = demoReducer(mobile, { type: "run-current-step" });

    expect(rejected.completedStepCount).toBe(0);
    expect(rejected.world).toBe(worldBefore);
    expect(rejected.announcement).toMatch(/Control room must complete the next action/i);
  });

  it("keeps updates immutable and reset restores the deterministic scenario", () => {
    const initial = createInitialSession();
    const snapshot = JSON.stringify(initial);
    const afterClash = demoReducer(initial, { type: "run-current-step" });

    expect(JSON.stringify(initial)).toBe(snapshot);
    expect(afterClash.world).not.toBe(initial.world);
    expect(afterClash.world.applications["dev-nova"].time).toBe("11:50");
    expect(afterClash.world.clashResolved).toBe(true);
    expect(demoReducer(afterClash, { type: "reset" })).toEqual(createInitialSession());
  });

  it("runs the complete two-company placement flow with handoff and cleanup effects", () => {
    let session = createInitialSession();

    for (const step of guidedSteps) {
      session = activateStep(session, step);
      const completedBefore = session.completedStepCount;
      session = demoReducer(session, { type: "run-current-step" });

      expect(session.completedStepCount).toBe(completedBefore + 1);

      if (step.id === "handover-atlas") {
        expect(session.world.applications["dev-atlas"].status).toBe("sent");
        expect(session.world.applications["dev-nova"].status).toBe("intransit");
        expect(session.world.candidates.dev.location).toMatch(/en route to Nova Labs/i);
        expect(session.world.audit[0]).toMatchObject({ actor: "System", system: true });
        expect(session.world.audit[0].message).toMatch(/Atlas → Nova route/);
      }
    }

    expect(session.completedStepCount).toBe(guidedSteps.length);
    expect(session.world.applications["dev-nova"].status).toBe("placed");
    expect(session.world.applications["dev-atlas"].status).toBe("idle");
    expect(session.world.placementCompanyId).toBe("nova");
    expect(session.world.preferenceResolved).toBe(true);
    expect(session.world.interviewsCompleted).toBe(2);
    expect(session.world.traceReviewed).toBe(true);
    expect(session.world.clockMinute).toBe(736);
    expect(session.world.audit).toHaveLength(24);
    expect(session.world.audit.some((event) => event.message.includes("Closed Dev’s competing Atlas application"))).toBe(true);
  });

  it("requires the matching company scope for company-owned actions", () => {
    let session = createInitialSession();

    for (const step of guidedSteps.slice(0, 3)) {
      session = activateStep(session, step);
      session = demoReducer(session, { type: "run-current-step" });
    }

    session = demoReducer(session, {
      type: "switch-role",
      role: "company",
      companyScope: "nova",
    });
    const rejected = demoReducer(session, { type: "run-current-step" });

    expect(rejected.completedStepCount).toBe(3);
    expect(rejected.world.applications["dev-atlas"].status).toBe("arrived");
    expect(rejected.announcement).toMatch(/Atlas Systems tracker must complete/i);
  });
});
