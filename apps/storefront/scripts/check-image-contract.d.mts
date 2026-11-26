export type ImageIssue = { file: string; line: number; tag: string; missing: string[] };

export function inspectImageTags(source: string, file?: string): ImageIssue[];

export function collectImageIssues(root?: string): Promise<{ files: number; issues: ImageIssue[] }>;
