export const roles = ['platform_super_admin', 'school_admin', 'principal', 'teacher', 'student', 'parent'] as const;
export type Role = (typeof roles)[number];
