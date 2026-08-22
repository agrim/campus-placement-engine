import { describe, expect, it } from "vitest";
import { createInitialSession } from "./scenario";
import {
  applicationsForCandidate,
  canSeeAccommodation,
  canSeePrivate,
  visibleCandidateIds,
} from "./selectors";
import type { DemoSession, RoleId } from "./types";

function asRole(role: RoleId): DemoSession {
  return { ...createInitialSession(), activeRole: role };
}

describe("role-aware demo selectors", () => {
  it("limits operational queues to the statuses owned by mobile, floor, and placement", () => {
    expect(visibleCandidateIds(asRole("mobile"))).toEqual(["dev", "isha", "zoya"]);
    expect(visibleCandidateIds(asRole("floor"))).toEqual(["aarav", "mira"]);
    expect(visibleCandidateIds(asRole("placement"))).toEqual(["isha"]);
  });

  it("limits a company profile to its selected company", () => {
    const atlas = { ...asRole("company"), companyScope: "atlas" as const };
    const nova = { ...asRole("company"), companyScope: "nova" as const };

    expect(visibleCandidateIds(atlas)).toEqual(["dev", "aarav"]);
    expect(visibleCandidateIds(nova)).toEqual(["dev", "isha", "mira"]);
    expect(applicationsForCandidate(atlas, "dev").map((application) => application.companyId)).toEqual(["atlas"]);
    expect(applicationsForCandidate(nova, "dev").map((application) => application.companyId)).toEqual(["nova"]);
  });

  it("keeps accommodation logistics separate from private placement context", () => {
    expect(canSeeAccommodation("mobile")).toBe(true);
    expect(canSeeAccommodation("floor")).toBe(true);
    expect(canSeeAccommodation("company")).toBe(false);

    expect(canSeePrivate("control")).toBe(true);
    expect(canSeePrivate("placement")).toBe(true);
    expect(canSeePrivate("auditor")).toBe(true);
    expect(canSeePrivate("mobile")).toBe(false);
    expect(canSeePrivate("floor")).toBe(false);
    expect(canSeePrivate("company")).toBe(false);
  });
});
