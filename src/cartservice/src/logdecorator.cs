using System;
using System.IO;
using log4net.Layout.Pattern;
using OpenTelemetry.Trace;

namespace Application.Logging
{
    public class TraceIdPatternConverter : PatternLayoutConverter
    {
        protected override void Convert(TextWriter writer, log4net.Core.LoggingEvent loggingEvent)
        {
            writer.Write(Tracer.CurrentSpan.Context.TraceId);
        }
    }

    public class SpanIdPatternConverter : PatternLayoutConverter
    {
        protected override void Convert(TextWriter writer, log4net.Core.LoggingEvent loggingEvent)
        {
            writer.Write(Tracer.CurrentSpan.Context.SpanId);
        }
    }
    public class ParentSpanIdPatternConverter : PatternLayoutConverter
    {
        protected override void Convert(TextWriter writer, log4net.Core.LoggingEvent loggingEvent)
        {
            writer.Write(Tracer.CurrentSpan.ParentSpanId);
        }
    }

}
