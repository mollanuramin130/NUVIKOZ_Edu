import {notFound} from 'next/navigation';
import Sessions from '../../../components/academic/sessions';
import Classes from '../../../components/academic/classes';
import Sections from '../../../components/academic/sections';
import Subjects from '../../../components/academic/subjects';
export default async function AcademicPage({params}: {params: Promise<{view: string}>}) {
  const {view} = await params;
  switch(view) {case 'sessions': return <Sessions/>; case 'classes': return <Classes/>; case 'sections': return <Sections/>; case 'subjects': return <Subjects/>; default: notFound();}
}
