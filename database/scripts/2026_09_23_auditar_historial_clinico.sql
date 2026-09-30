/*
    MedGo+ - Auditoría no destructiva del historial clínico
    Este script solo consulta información. No modifica registros.
*/

/* 1. Consultas completadas y cantidad real de medicamentos recetados. */
SELECT
    C.CitaID,
    CON.ConsultaID,
    C.FechaHora,
    C.EstadoCita,
    P.Nombre + ' ' + P.Apellido AS Paciente,
    D.Nombre + ' ' + D.Apellido AS Doctor,
    COUNT(R.RecetaID) AS MedicamentosRecetados
FROM Consultas AS CON
INNER JOIN Citas AS C ON C.CitaID = CON.CitaID
INNER JOIN Pacientes AS P ON P.PacienteID = C.PacienteID
INNER JOIN Doctores AS D ON D.DoctorID = C.DoctorID
LEFT JOIN Recetas AS R ON R.ConsultaID = CON.ConsultaID
WHERE UPPER(LTRIM(RTRIM(C.EstadoCita))) IN ('COMPLETADA', 'FINALIZADA')
GROUP BY
    C.CitaID,
    CON.ConsultaID,
    C.FechaHora,
    C.EstadoCita,
    P.Nombre,
    P.Apellido,
    D.Nombre,
    D.Apellido
ORDER BY C.FechaHora DESC;

/* 2. Inconsistencias reales: consultas completadas sin ninguna receta. */
SELECT
    C.CitaID,
    CON.ConsultaID,
    C.FechaHora,
    P.Nombre + ' ' + P.Apellido AS Paciente,
    D.Nombre + ' ' + D.Apellido AS Doctor,
    CON.Diagnostico
FROM Consultas AS CON
INNER JOIN Citas AS C ON C.CitaID = CON.CitaID
INNER JOIN Pacientes AS P ON P.PacienteID = C.PacienteID
INNER JOIN Doctores AS D ON D.DoctorID = C.DoctorID
WHERE UPPER(LTRIM(RTRIM(C.EstadoCita))) IN ('COMPLETADA', 'FINALIZADA')
  AND NOT EXISTS (
      SELECT 1
      FROM Recetas AS R
      WHERE R.ConsultaID = CON.ConsultaID
  )
ORDER BY C.FechaHora DESC;

/* 3. Recetas relacionadas por la llave correcta ConsultaID. */
SELECT
    C.CitaID,
    CON.ConsultaID,
    R.RecetaID,
    R.CodigoCanje,
    R.NombreMedicamento,
    R.Dosis,
    R.Indicaciones,
    R.Estado,
    R.EstadoReceta,
    R.FechaEmision
FROM Recetas AS R
INNER JOIN Consultas AS CON ON CON.ConsultaID = R.ConsultaID
INNER JOIN Citas AS C ON C.CitaID = CON.CitaID
ORDER BY C.FechaHora DESC, R.RecetaID;

/* 4. Órdenes y exámenes de laboratorio asociados a cada consulta. */
SELECT
    C.CitaID,
    CON.ConsultaID,
    OL.OrdenID,
    OL.CodigoOrden,
    E.NombreEntidad AS Laboratorio,
    OL.Estado AS EstadoOrden,
    CEL.NombreExamen,
    OED.Estado AS EstadoExamen,
    OL.FechaOrden
FROM Consultas AS CON
INNER JOIN Citas AS C ON C.CitaID = CON.CitaID
INNER JOIN OrdenesLaboratorio AS OL ON OL.ConsultaID = CON.ConsultaID
LEFT JOIN Entidades AS E ON E.EntidadID = OL.LaboratorioID
LEFT JOIN OrdenExamenDetalle AS OED ON OED.OrdenID = OL.OrdenID
LEFT JOIN CatalogoExamenesLab AS CEL ON CEL.ExamID = OED.ExamID
ORDER BY C.FechaHora DESC, OL.OrdenID, CEL.NombreExamen;
