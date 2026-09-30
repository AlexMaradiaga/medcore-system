SET XACT_ABORT ON;
GO

BEGIN TRANSACTION;
GO

/*
 |--------------------------------------------------------------------------
 | 1. Discriminador del titular de la suscripción
 |--------------------------------------------------------------------------
 */
IF COL_LENGTH('dbo.Sistema_Suscripciones_SaaS', 'TipoSuscriptor') IS NULL
BEGIN
    ALTER TABLE dbo.Sistema_Suscripciones_SaaS
        ADD TipoSuscriptor nvarchar(20) NULL;
END;
GO

IF COL_LENGTH('dbo.HistorialPagosSaaS', 'TipoSuscriptor') IS NULL
BEGIN
    ALTER TABLE dbo.HistorialPagosSaaS
        ADD TipoSuscriptor nvarchar(20) NULL;
END;
GO

IF COL_LENGTH('dbo.HistorialPagosSaaS', 'EntidadID') IS NULL
BEGIN
    ALTER TABLE dbo.HistorialPagosSaaS
        ADD EntidadID int NULL;
END;
GO

IF COL_LENGTH('dbo.HistorialPagosSaaS', 'SuscripcionSaaSID') IS NULL
BEGIN
    ALTER TABLE dbo.HistorialPagosSaaS
        ADD SuscripcionSaaSID int NULL;
END;
GO

/* Los pagos SaaS de doctores no pertenecen a una entidad. */
ALTER TABLE dbo.Detalle_Pagos ALTER COLUMN EntidadID int NULL;
GO

/*
 |--------------------------------------------------------------------------
 | 2. Reclasificar suscripciones existentes
 |    La relación con Doctores tiene prioridad sobre EntidadID, porque un
 |    médico puede estar afiliado a una clínica sin que la clínica sea titular.
 |--------------------------------------------------------------------------
 */
UPDATE S
SET S.TipoSuscriptor = N'Doctor',
    S.EntidadID = NULL
FROM dbo.Sistema_Suscripciones_SaaS S
INNER JOIN dbo.Doctores D ON D.UsuarioID = S.UsuarioID;
GO

UPDATE S
SET S.TipoSuscriptor = E.TipoEntidad
FROM dbo.Sistema_Suscripciones_SaaS S
INNER JOIN dbo.Entidades E ON E.EntidadID = S.EntidadID
WHERE S.TipoSuscriptor IS NULL;
GO

/* Historial existente: UsuarioID 4 y cualquier otro usuario médico. */
UPDATE H
SET H.TipoSuscriptor = N'Doctor',
    H.EntidadID = NULL
FROM dbo.HistorialPagosSaaS H
INNER JOIN dbo.Doctores D ON D.UsuarioID = H.UsuarioID;
GO

UPDATE H
SET H.TipoSuscriptor = E.TipoEntidad,
    H.EntidadID = U.EntidadID
FROM dbo.HistorialPagosSaaS H
INNER JOIN dbo.Usuarios U ON U.UsuarioID = H.UsuarioID
INNER JOIN dbo.Entidades E ON E.EntidadID = U.EntidadID
WHERE H.TipoSuscriptor IS NULL;
GO

/*
 |--------------------------------------------------------------------------
 | 3. Materializar el plan Gratis: ya no será un valor inventado al reportar
 |--------------------------------------------------------------------------
 */
INSERT INTO dbo.Sistema_Suscripciones_SaaS
    (EntidadID, UsuarioID, TipoSuscriptor, TipoPlan, EstadoSuscripcion,
     FechaVencimiento, TokenPasarela, ActualizadoEn)
SELECT
    NULL,
    D.UsuarioID,
    N'Doctor',
    N'Gratis',
    N'ACTIVA',
    CONVERT(datetime, '9999-12-31', 120),
    NULL,
    GETDATE()
FROM dbo.Doctores D
INNER JOIN dbo.Usuarios U ON U.UsuarioID = D.UsuarioID
WHERE D.Estado = 1
  AND ISNULL(D.EsVerificado, 0) = 1
  AND U.Estado = 1
  AND NOT EXISTS (
      SELECT 1
      FROM dbo.Sistema_Suscripciones_SaaS S
      WHERE S.TipoSuscriptor = N'Doctor'
        AND S.UsuarioID = D.UsuarioID
  );
GO

INSERT INTO dbo.Sistema_Suscripciones_SaaS
    (EntidadID, UsuarioID, TipoSuscriptor, TipoPlan, EstadoSuscripcion,
     FechaVencimiento, TokenPasarela, ActualizadoEn)
SELECT
    E.EntidadID,
    (
        SELECT TOP (1) U.UsuarioID
        FROM dbo.Usuarios U
        WHERE U.EntidadID = E.EntidadID AND U.Estado = 1
        ORDER BY U.UsuarioID
    ),
    E.TipoEntidad,
    N'Gratis',
    N'ACTIVA',
    CONVERT(datetime, '9999-12-31', 120),
    NULL,
    GETDATE()
FROM dbo.Entidades E
WHERE E.Estado = 1
  AND NOT EXISTS (
      SELECT 1
      FROM dbo.Sistema_Suscripciones_SaaS S
      WHERE S.EntidadID = E.EntidadID
        AND S.TipoSuscriptor = E.TipoEntidad
  );
GO

/* Vincular historial con la suscripción ya normalizada. */
UPDATE H
SET H.SuscripcionSaaSID = S.SuscripcionSaaSID
FROM dbo.HistorialPagosSaaS H
INNER JOIN dbo.Sistema_Suscripciones_SaaS S
    ON S.TipoSuscriptor = H.TipoSuscriptor
   AND (
        (H.TipoSuscriptor = N'Doctor' AND S.UsuarioID = H.UsuarioID)
        OR
        (H.TipoSuscriptor <> N'Doctor' AND S.EntidadID = H.EntidadID)
   )
WHERE H.SuscripcionSaaSID IS NULL;
GO

IF EXISTS (SELECT 1 FROM dbo.Sistema_Suscripciones_SaaS WHERE TipoSuscriptor IS NULL)
    THROW 51000, 'Hay suscripciones que no pudieron clasificarse. Revise UsuarioID y EntidadID antes de continuar.', 1;

IF EXISTS (SELECT 1 FROM dbo.HistorialPagosSaaS WHERE TipoSuscriptor IS NULL)
    THROW 51001, 'Hay pagos históricos que no pudieron clasificarse. Revise sus titulares antes de continuar.', 1;
GO

ALTER TABLE dbo.Sistema_Suscripciones_SaaS
    ALTER COLUMN TipoSuscriptor nvarchar(20) NOT NULL;

ALTER TABLE dbo.HistorialPagosSaaS
    ALTER COLUMN TipoSuscriptor nvarchar(20) NOT NULL;
GO

/*
 |--------------------------------------------------------------------------
 | 4. Integridad referencial y dominio permitido
 |--------------------------------------------------------------------------
 */
IF OBJECT_ID('dbo.CK_SuscripcionesSaaS_TipoSuscriptor', 'C') IS NULL
BEGIN
    ALTER TABLE dbo.Sistema_Suscripciones_SaaS WITH CHECK
    ADD CONSTRAINT CK_SuscripcionesSaaS_TipoSuscriptor CHECK
        (TipoSuscriptor IN (N'Doctor', N'Clinica', N'Farmacia', N'Laboratorio'));
END;
GO

IF OBJECT_ID('dbo.CK_SuscripcionesSaaS_Titular', 'C') IS NULL
BEGIN
    ALTER TABLE dbo.Sistema_Suscripciones_SaaS WITH CHECK
    ADD CONSTRAINT CK_SuscripcionesSaaS_Titular CHECK
    (
        (TipoSuscriptor = N'Doctor' AND UsuarioID IS NOT NULL AND EntidadID IS NULL)
        OR
        (TipoSuscriptor IN (N'Clinica', N'Farmacia', N'Laboratorio') AND EntidadID IS NOT NULL)
    );
END;
GO

IF OBJECT_ID('dbo.CK_HistorialPagosSaaS_TipoSuscriptor', 'C') IS NULL
BEGIN
    ALTER TABLE dbo.HistorialPagosSaaS WITH CHECK
    ADD CONSTRAINT CK_HistorialPagosSaaS_TipoSuscriptor CHECK
        (TipoSuscriptor IN (N'Doctor', N'Clinica', N'Farmacia', N'Laboratorio'));
END;
GO

IF OBJECT_ID('dbo.FK_HistorialPagosSaaS_Entidad', 'F') IS NULL
BEGIN
    ALTER TABLE dbo.HistorialPagosSaaS WITH CHECK
    ADD CONSTRAINT FK_HistorialPagosSaaS_Entidad
        FOREIGN KEY (EntidadID) REFERENCES dbo.Entidades(EntidadID);
END;
GO

IF OBJECT_ID('dbo.FK_HistorialPagosSaaS_Suscripcion', 'F') IS NULL
BEGIN
    ALTER TABLE dbo.HistorialPagosSaaS WITH CHECK
    ADD CONSTRAINT FK_HistorialPagosSaaS_Suscripcion
        FOREIGN KEY (SuscripcionSaaSID)
        REFERENCES dbo.Sistema_Suscripciones_SaaS(SuscripcionSaaSID);
END;
GO

/* Un solo estado vigente por titular; evita volver a mezclar o duplicar filas. */
IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name = 'UX_SuscripcionesSaaS_Doctor' AND object_id = OBJECT_ID('dbo.Sistema_Suscripciones_SaaS'))
BEGIN
    CREATE UNIQUE INDEX UX_SuscripcionesSaaS_Doctor
        ON dbo.Sistema_Suscripciones_SaaS (UsuarioID)
        WHERE TipoSuscriptor = N'Doctor' AND UsuarioID IS NOT NULL;
END;
GO

IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name = 'UX_SuscripcionesSaaS_Entidad' AND object_id = OBJECT_ID('dbo.Sistema_Suscripciones_SaaS'))
BEGIN
    CREATE UNIQUE INDEX UX_SuscripcionesSaaS_Entidad
        ON dbo.Sistema_Suscripciones_SaaS (TipoSuscriptor, EntidadID)
        WHERE EntidadID IS NOT NULL;
END;
GO

COMMIT TRANSACTION;
GO

/* Verificación final */
SELECT TipoSuscriptor, TipoPlan, COUNT(*) AS Suscriptores
FROM dbo.Sistema_Suscripciones_SaaS
WHERE UPPER(LTRIM(RTRIM(EstadoSuscripcion))) IN ('ACTIVO', 'ACTIVA', 'VIGENTE')
GROUP BY TipoSuscriptor, TipoPlan
ORDER BY TipoSuscriptor, TipoPlan;
GO
