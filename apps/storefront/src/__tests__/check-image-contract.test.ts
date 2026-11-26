import { describe, expect, it } from "vitest";
import { inspectImageTags } from "../../scripts/check-image-contract.mjs";

describe("image contract inspector", () => {
  it("accepts images with explicit width, height and alt", () => {
    expect(inspectImageTags('<img src="/a.svg" alt="A" width="10" height="10" />', "a.tsx")).toEqual([]);
  });

  it("reports dimensions and alt that are missing", () => {
    const issues = inspectImageTags('<img src="/a.svg" />', "src/a.tsx");
    expect(issues).toHaveLength(1);
    expect(issues[0]).toMatchObject({ file: "src/a.tsx", line: 1, missing: ["width", "height", "alt"] });
  });

  it("reports only the missing attribute across multiple lines", () => {
    const source = '<div>\n  <img\n    src="/a.svg"\n    width="10"\n    height="10"\n  />\n</div>';
    const issues = inspectImageTags(source, "src/b.tsx");
    expect(issues).toHaveLength(1);
    expect(issues[0]).toMatchObject({ line: 2, missing: ["alt"] });
  });

  it("treats an empty alt as present for decorative media", () => {
    expect(inspectImageTags('<img src="/a.svg" alt="" width="10" height="10" />')).toEqual([]);
  });
});
