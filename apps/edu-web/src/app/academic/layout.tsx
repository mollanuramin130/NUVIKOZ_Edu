import type {ReactNode} from 'react';
import AcademicWorkspace from '../../components/academic/workspace';
import './academic.css';
export default function AcademicLayout({children}: {children: ReactNode}) { return <AcademicWorkspace>{children}</AcademicWorkspace>; }
